import { defineConfig } from 'vitest/config';

// happy-dom gives the tests a document for the sign-out form and a
// same-origin URL so relative endpoints resolve like in a browser.
export default defineConfig({
  test: {
    environment: 'happy-dom',
    environmentOptions: {
      happyDOM: {
        url: 'http://localhost/',
      },
    },
  },
});
