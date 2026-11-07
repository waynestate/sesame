import {
  computed,
  getCurrentScope,
  inject,
  onScopeDispose,
  provide,
  ref,
  type App,
  type ComputedRef,
  type InjectionKey,
  type Plugin,
  type Ref,
} from 'vue';

/**
 * The signed-in status answer, as GET /sesame/status sends it.
 * The times are UTC ISO 8601 strings.
 */
export interface SesameStatus {
  signedIn: true;
  username: string;
  driver: string;
  authenticatedAt: string;
  authTime: string | null;
  amr: string[];
  idleExpiresAt: string;
  absoluteExpiresAt: string;
  logoutToken: string;
  user: Record<string, unknown> | null;
}

/** The signed-out status answer: a 401 with one field. */
export interface SesameSignedOut {
  signedIn: false;
}

/** Either status answer. */
export type SesameAnswer = SesameStatus | SesameSignedOut;

export interface UseSesameOptions {
  /** Where to read the status. Default: /sesame/status. */
  statusUrl?: string;
  /** Where the sign-out form posts. Default: /logout. */
  logoutUrl?: string;
  /** Fetch implementation. Default: globalThis.fetch. Injected in tests. */
  fetch?: typeof globalThis.fetch;
  /** Current time in milliseconds. Default: () => Date.now(). Injected in tests. */
  now?: () => number;
}

export interface UseSesame {
  /** The last status answer, or null before the first read settles. */
  status: Ref<SesameAnswer | null>;
  /** True while a status read is in flight. */
  loading: Ref<boolean>;
  /** The last read failure, or null when the last read succeeded. */
  error: Ref<Error | null>;
  /** True once the stored answer says someone is signed in. */
  signedIn: ComputedRef<boolean>;
  /** The app's own user fields, or null when signed out or absent. */
  user: ComputedRef<Record<string, unknown> | null>;
  /** True once now() reaches the earlier of the two expiry times. */
  expired: ComputedRef<boolean>;
  /** Re-read the status once. Call it on real user actions, never on a timer. */
  refresh: () => Promise<void>;
  /** Post the sign-out form and leave the page. Does nothing when signed out. */
  logout: () => void;
}

/**
 * Read the SESAME status once and keep it reactive.
 *
 * The adapter never polls: reading the status counts as activity on the
 * server side, so a poll would keep the session alive past its idle timeout.
 * It reads once when called and again on refresh(), and expired flips by
 * itself when the clock reaches the earlier expiry.
 */
export function useSesame(options: UseSesameOptions = {}): UseSesame {
  const statusUrl = options.statusUrl ?? '/sesame/status';
  const logoutUrl = options.logoutUrl ?? '/logout';
  const fetchFn = options.fetch ?? globalThis.fetch;
  const now = options.now ?? (() => Date.now());

  const status = ref<SesameAnswer | null>(null);
  const loading = ref(true);
  const error = ref<Error | null>(null);

  // The tick flips exactly at the earlier expiry, so expired becomes
  // reactive without polling: the timer does the waiting, the clock does
  // the deciding.
  const tick = ref(0);
  let expiryTimer: ReturnType<typeof setTimeout> | undefined;

  const signedIn = computed(() => status.value?.signedIn === true);
  const user = computed(() => (status.value?.signedIn === true ? status.value.user : null));
  const expired = computed(() => {
    tick.value; // re-check when the expiry timer flips the tick
    const answer = status.value;
    if (!answer || answer.signedIn !== true) {
      return false;
    }
    const earliest = Math.min(Date.parse(answer.idleExpiresAt), Date.parse(answer.absoluteExpiresAt));
    return now() >= earliest;
  });

  function clearExpiryTimer(): void {
    if (expiryTimer !== undefined) {
      clearTimeout(expiryTimer);
      expiryTimer = undefined;
    }
  }

  function armExpiryTimer(answer: SesameStatus): void {
    clearExpiryTimer();
    const earliest = Math.min(Date.parse(answer.idleExpiresAt), Date.parse(answer.absoluteExpiresAt));
    const delay = Math.max(0, earliest - now());
    expiryTimer = setTimeout(() => {
      expiryTimer = undefined;
      tick.value += 1;
    }, delay);
  }

  async function refresh(): Promise<void> {
    loading.value = true;
    try {
      const response = await fetchFn(statusUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (response.status === 200) {
        const body = (await response.json()) as SesameAnswer;
        if (body.signedIn === true) {
          status.value = body;
          armExpiryTimer(body);
        } else {
          status.value = { signedIn: false };
          clearExpiryTimer();
        }
        error.value = null;
      } else if (response.status === 401) {
        status.value = { signedIn: false };
        clearExpiryTimer();
        error.value = null;
      } else {
        error.value = new Error('The SESAME status answered ' + String(response.status) + '.');
      }
    } catch (cause) {
      error.value = cause instanceof Error ? cause : new Error('The SESAME status read failed.');
    } finally {
      loading.value = false;
    }
  }

  function logout(): void {
    const answer = status.value;
    if (!answer || answer.signedIn !== true) {
      return;
    }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = logoutUrl;
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'sesame_logout_token';
    token.value = answer.logoutToken;
    form.append(token);
    document.body.append(form);
    form.submit();
  }

  if (getCurrentScope()) {
    onScopeDispose(clearExpiryTimer);
  }

  void refresh();

  return { status, loading, error, signedIn, user, expired, refresh, logout };
}

const sesameKey: InjectionKey<UseSesame> = Symbol('sesame');

/**
 * A Vue plugin that reads the status once for the whole app and provides
 * it. Install it in main.ts: createApp(App).use(createSesame()).
 */
export function createSesame(options: UseSesameOptions = {}): Plugin {
  return {
    install(app: App): void {
      app.provide(sesameKey, useSesame(options));
    },
  };
}

/** Read the app-wide SESAME state from a component's setup(). */
export function injectSesame(): UseSesame {
  const sesame = inject(sesameKey);
  if (sesame === undefined) {
    throw new Error('injectSesame() needs the app installed with createSesame().');
  }
  return sesame;
}
