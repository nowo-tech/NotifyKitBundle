import { test as base, expect, type BrowserContext } from '@playwright/test';

/**
 * The demo runs in dev with FrankenPHP hot reload: a Mercure EventSource in every page reloads (or re-fetches) the page
 * whenever a watched file changes, and Symfony's dev cache writes such files while the suite runs. A reload in the middle
 * of the sign-in navigation puts the sign-in form back, so the bell never shows. The bundle's realtime mode here is
 * `poll`, so the specs do not need Mercure: every context aborts the hub (same as the ChatKit demo specs).
 */
export async function withoutHotReload(context: BrowserContext): Promise<BrowserContext> {
  await context.route('**/.well-known/mercure**', (route) => route.abort());

  return context;
}

export const test = base.extend({
  context: async ({ context }, use) => {
    await withoutHotReload(context);
    await use(context);
  },
});

export { expect };
