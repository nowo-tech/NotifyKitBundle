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
