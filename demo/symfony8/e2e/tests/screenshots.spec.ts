import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — full demo frame: demo masthead + the bundle status table + Symfony WebProfiler toolbar.
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
  const masthead = await boxOf(page, '[data-testid="demo-masthead"]');
  const main = await boxOf(page, 'main');
  const profiler = (await boxOf(page, '.sf-toolbar')) ?? (await boxOf(page, '.sf-minitoolbar'));
  if (!masthead || !main) {
    throw new Error('Missing demo masthead or main for the NotifyKit screenshot clip');
  }
  const boxes = [masthead, main, profiler].filter(Boolean) as Box[];
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
  await page
    .locator('.sf-toolbar .sf-toolbar-block, .sf-toolbar-status, .sf-minitoolbar')
    .first()
    .waitFor({ state: 'visible', timeout: 10000 })
    .catch(() => {});
  await page.screenshot({ path: resolve(outDir, 'overview.png'), clip: await clipDemoFrame(page) });
});
