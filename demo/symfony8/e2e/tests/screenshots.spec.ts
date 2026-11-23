import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — the demo platform shell with the bundle status table (no Symfony WebProfiler toolbar).
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

type Box = { x: number; y: number; width: number; height: number };
type Page = import('@playwright/test').Page;

async function boxOf(page: Page, selector: string): Promise<Box | null> {
  const loc = page.locator(selector).first();
  if ((await loc.count()) === 0) {
    return null;
  }
  return loc.boundingBox();
}

async function clipDemoFrame(page: Page): Promise<Box> {
  const shell = await boxOf(page, '[data-testid="demo-platform"]');
  const main = await boxOf(page, 'main');
  if (!shell || !main) {
    throw new Error('Missing demo platform shell or main for the NotifyKit screenshot clip');
  }
  const boxes = [shell, main] as Box[];
  const x = Math.min(...boxes.map((b) => b.x));
  const y = Math.min(...boxes.map((b) => b.y));
  const right = Math.max(...boxes.map((b) => b.x + b.width));
  const bottom = Math.max(...boxes.map((b) => b.y + b.height));
  const pad = 8;
  return {
    x: Math.max(0, x - pad),
    y: Math.max(0, y - pad),
    width: right - x + pad * 2,
    height: bottom - y + pad * 2,
  };
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test('overview — bundle wiring on the UiKit shell', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.goto('/');
  await expect(page.locator('[data-testid="demo-status"]')).toBeVisible();
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  // The Symfony toolbar is not part of the demo platform: README screenshots never show it.
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await page.screenshot({ path: resolve(outDir, 'overview.png'), clip: await clipDemoFrame(page) });
});

async function signInAsAlice(page: Page): Promise<void> {
  await page.goto('/');
  await page.locator('[data-testid="demo-sign-in"] input[value="alice"]').check();
  await page.locator('[data-testid="demo-sign-in"] [type="submit"]').first().click();
  await expect(page.locator('[data-testid="notify-kit-bell"]')).toBeVisible();
  for (const kind of ['welcome', 'project']) {
    await page.locator('[data-testid="demo-notify"] select').selectOption(kind);
    await page.locator('[data-testid="demo-notify"] [type="submit"]').first().click();
  }
}

test('bell — the open header bell menu', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
  await signInAsAlice(page);
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await page.locator('[data-testid="notify-kit-bell-button"]').click();
  await expect(page.locator('.nowo-notify-kit-item').first()).toBeVisible();
  // Let the 120 ms opening animation finish.
  await page.waitForTimeout(400);
  await page.screenshot({ path: resolve(outDir, 'bell.png'), clip: await clipDemoFrame(page) });
});

test('inbox — the inbox page', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
  await signInAsAlice(page);
  await page.goto('/notifications');
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await expect(page.locator('[data-testid="notify-kit-inbox"]')).toBeVisible();
  await page.screenshot({ path: resolve(outDir, 'inbox.png'), clip: await clipDemoFrame(page) });
});

test('preferences — the preferences page (S3)', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
  await signInAsAlice(page);
  await page.goto('/account/notifications');
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await expect(page.locator('[data-testid="notify-kit-matrix"]')).toBeVisible();
  await page.screenshot({ path: resolve(outDir, 'preferences.png'), clip: await clipDemoFrame(page) });
});
