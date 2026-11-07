import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, defineComponent, h } from 'vue';
import { createSesame, injectSesame, useSesame } from '../src/index';
import signedInFixture from './fixtures/status-signed-in.json';
import signedOutFixture from './fixtures/status-signed-out.json';

// The fixture session signs in at 09:15 UTC and idles out at 11:15 UTC, so
// every test pins the clock inside that window unless it says otherwise.
const INSIDE_WINDOW = Date.parse('2026-11-03T09:20:00Z');

function jsonResponse(body: unknown, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

afterEach(() => {
  vi.useRealTimers();
  document.body.innerHTML = '';
});

describe('useSesame', () => {
  it('reads the signed-in answer and exposes the user facts', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(signedInFixture, 200));
    const sesame = useSesame({ fetch: fetchMock, now: () => INSIDE_WINDOW });

    await vi.waitFor(() => expect(sesame.loading.value).toBe(false));

    expect(sesame.status.value).toEqual(signedInFixture);
    expect(sesame.signedIn.value).toBe(true);
    expect(sesame.user.value).toEqual(signedInFixture.user);
    expect(sesame.error.value).toBeNull();
    expect(sesame.expired.value).toBe(false);
  });

  it('answers signed out on a 401', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(signedOutFixture, 401));
    const sesame = useSesame({ fetch: fetchMock, now: () => INSIDE_WINDOW });

    await vi.waitFor(() => expect(sesame.loading.value).toBe(false));

    expect(sesame.status.value).toEqual({ signedIn: false });
    expect(sesame.signedIn.value).toBe(false);
    expect(sesame.user.value).toBeNull();
    expect(sesame.expired.value).toBe(false);
    expect(sesame.error.value).toBeNull();
  });

  it('keeps the old status and records the error on any other answer', async () => {
    let calls = 0;
    const fetchMock = vi.fn(async () => {
      calls += 1;
      return calls === 1 ? jsonResponse(signedInFixture, 200) : jsonResponse({}, 500);
    });
    const sesame = useSesame({ fetch: fetchMock, now: () => INSIDE_WINDOW });

    await vi.waitFor(() => expect(sesame.loading.value).toBe(false));
    expect(sesame.signedIn.value).toBe(true);

    await sesame.refresh();

    expect(sesame.error.value).toBeInstanceOf(Error);
    expect(sesame.status.value).toEqual(signedInFixture);
    expect(sesame.signedIn.value).toBe(true);
    expect(sesame.loading.value).toBe(false);
  });

  it('reads with same-origin credentials, no-store caching and a JSON accept header', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(signedOutFixture, 401));
    const sesame = useSesame({ fetch: fetchMock, statusUrl: '/custom/status', now: () => INSIDE_WINDOW });

    await vi.waitFor(() => expect(sesame.loading.value).toBe(false));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith(
      '/custom/status',
      expect.objectContaining({
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      }),
    );
  });

  it('does not poll: no second fetch an hour later', async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn(async () => jsonResponse(signedInFixture, 200));
    const sesame = useSesame({ fetch: fetchMock, now: () => Date.now() });

    await vi.advanceTimersByTimeAsync(0);
    expect(sesame.loading.value).toBe(false);
    expect(fetchMock).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('flips expired at the idle expiry without another fetch', async () => {
    vi.useFakeTimers({ now: Date.parse('2026-11-03T09:15:00Z') });
    const fetchMock = vi.fn(async () => jsonResponse(signedInFixture, 200));
    const sesame = useSesame({ fetch: fetchMock, now: () => Date.now() });

    await vi.advanceTimersByTimeAsync(0);
    expect(sesame.expired.value).toBe(false);

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
    expect(sesame.expired.value).toBe(false);

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
    expect(sesame.expired.value).toBe(true);

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('posts the sign-out form carrying the logout token', async () => {
    const submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetchMock = vi.fn(async () => jsonResponse(signedInFixture, 200));
    const sesame = useSesame({ fetch: fetchMock, now: () => INSIDE_WINDOW });

    await vi.waitFor(() => expect(sesame.loading.value).toBe(false));
    sesame.logout();

    expect(submitSpy).toHaveBeenCalledTimes(1);
    const form = document.querySelector('form');
    expect(form).not.toBeNull();
    expect(form?.method).toBe('post');
    expect(form?.getAttribute('action')).toBe('/logout');
    const token = form?.querySelector('input[name="sesame_logout_token"]');
    expect(token).not.toBeNull();
    expect(token?.value).toBe('3f7a1c9e8b2d4a6f');
    submitSpy.mockRestore();
  });

  it('does nothing when no one is signed in', async () => {
    const submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    const fetchMock = vi.fn(async () => jsonResponse(signedOutFixture, 401));
    const sesame = useSesame({ fetch: fetchMock, now: () => INSIDE_WINDOW });

    await vi.waitFor(() => expect(sesame.loading.value).toBe(false));
    sesame.logout();

    expect(submitSpy).not.toHaveBeenCalled();
    expect(document.querySelector('form')).toBeNull();
    submitSpy.mockRestore();
  });
});

describe('createSesame and injectSesame', () => {
  it('shares one state between the plugin and the components', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(signedInFixture, 200));
    let seen: ReturnType<typeof injectSesame> | undefined;
    const Child = defineComponent({
      setup() {
        seen = injectSesame();
        return () => h('div');
      },
    });

    const root = document.createElement('div');
    document.body.append(root);
    const app = createApp(Child);
    app.use(createSesame({ fetch: fetchMock, now: () => INSIDE_WINDOW }));
    app.mount(root);

    await vi.waitFor(() => expect(seen?.signedIn.value).toBe(true));
    expect(fetchMock).toHaveBeenCalledTimes(1);

    app.unmount();
  });

  it('throws a clear error when the plugin was not installed', () => {
    const Child = defineComponent({
      setup() {
        return () => {
          expect(() => injectSesame()).toThrowError(/createSesame/);
          return h('div');
        };
      },
    });

    const root = document.createElement('div');
    document.body.append(root);
    const app = createApp(Child);
    app.mount(root);
    app.unmount();
  });
});
