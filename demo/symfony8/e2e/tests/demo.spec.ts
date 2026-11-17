import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — NotifyKitBundle demo happy path: the home page renders on the UiKit shell and reports the bundle
 * wiring (installed, parameters, database, FrankenPHP mode). Fails on console errors.
 */
test.describe('NotifyKitBundle demo', () => {
  test('home reports the bundle wiring on the UiKit shell', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') errors.push(m.text());
    });
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.locator('[data-testid="demo-masthead"]')).toBeVisible();
    const status = page.locator('[data-testid="demo-status"]');
    await expect(status).toBeVisible();
    await expect(status).toContainText('NowoNotifyKitBundle');
    await expect(status).toContainText('MySQL');
    await expect(status).toContainText('worker mode');
    expect(errors.filter((e) => !/favicon|mercure|EventSource/i.test(e))).toEqual([]);
  });
});
