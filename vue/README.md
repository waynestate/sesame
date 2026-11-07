# SESAME for Vue

A thin Vue adapter for SESAME single-page-app login state. It reads the
status answer once, tells you who is signed in and when the session expires,
and signs you out with the same CSRF-checked POST the rest of the app uses.
No state library, no router coupling.

## Install

The package is not published to npm yet. Point your app at this folder:

```sh
npm install /path/to/sesame/vue
```

(or add it to `package.json` as a `file:` dependency.)

## Usage

In `main.ts`:

```ts
import { createApp } from 'vue';
import { createSesame } from '@waynestate/sesame-vue';
import App from './App.vue';

createApp(App).use(createSesame()).mount('#app');
```

In a component:

```ts
import { injectSesame } from '@waynestate/sesame-vue';

export default {
  setup() {
    const { signedIn, user, expired, refresh, logout } = injectSesame();
    return { signedIn, user, expired, refresh, logout };
  },
};
```

`signedIn`, `user`, and `expired` are reactive. `refresh()` re-reads the
status; call it when the user performs a real action, never on a timer.
`logout()` posts the sign-out form and leaves the page.

Prefer not to use the plugin? Call `useSesame(options?)` directly in any
`setup()`. Options: `statusUrl` (default `/sesame/status`) and `logoutUrl`
(default `/logout`), plus `fetch` and `now` overrides for tests.

## Why there is no polling

Reading the status counts as activity on the server side: it moves the
session's `last_seen`, so a poll would keep the session alive past its idle
timeout. The adapter reads once, and `expired` flips locally when the clock
reaches the earlier of `idleExpiresAt` and `absoluteExpiresAt`. Re-read with
`refresh()` when the user does something.

## Signing out

`logout()` builds a `<form method="post">` to `logoutUrl` with one hidden
field, `sesame_logout_token`, carrying the token from the status answer, and
submits it. That is the same CSRF-checked logout the server renders. When
nobody is signed in it does nothing.

Open sesame, once. The door only stays open while you keep walking through
it.
