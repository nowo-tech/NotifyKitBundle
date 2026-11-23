import { test, expect, withoutHotReload } from './fixtures';
import type { Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * REQ-DEMO-013 — the S2 flows of the contract (§14 "UI"): the header bell (count, menu, mark read), the inbox page
 * (rows, mark all as read), deny by default (Bob never sees Alice's project rows), the live badge (a change made in
 * another tab reaches the bell within one poll interval) and the operator page (Alice only). Fails on console errors.
 */
async function signIn(page: Page, person: 'alice' | 'bob'): Promise<void> {
  await page.goto('/');
  if (await page.locator('[data-testid="notify-kit-bell"]').count()) {
    await page.goto('/logout');
  }
  await page.locator(`[data-testid="demo-sign-in"] input[value="${person}"]`).check();
  await page.locator('[data-testid="demo-sign-in"] button[type="submit"], [data-testid="demo-sign-in"] [type="submit"]').first().click();
  await expect(page.locator('[data-testid="notify-kit-bell"]')).toBeVisible({ timeout: 15_000 });
}

async function send(page: Page, kind: 'welcome' | 'project'): Promise<void> {
  await page.goto('/');
  await page.locator('[data-testid="demo-notify"] select').selectOption(kind);
  await page.locator('[data-testid="demo-notify"] [type="submit"]').first().click();
  await expect(page.locator('[data-testid="demo-status"]')).toBeVisible();
}

async function clearInbox(page: Page): Promise<void> {
  await page.goto('/notifications');
  const all = page.locator('[data-testid="notify-kit-read-all"]');
  if (await all.count()) {
    await all.first().click();
  }
}

test.describe('NotifyKitBundle S2: bell, inbox, live badge', () => {
  test('the bell counts, opens its menu and marks a row read', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') errors.push(m.text());
    });
    await signIn(page, 'alice');
    await clearInbox(page);
    await send(page, 'welcome');
    const badge = page.locator('[data-testid="notify-kit-badge"]');
    await expect(badge).toHaveText('1');

    await page.locator('[data-testid="notify-kit-bell-button"]').click();
    const item = page.locator('.nowo-notify-kit-item').first();
    await expect(item).toContainText('says welcome to the demo');
    await expect(item).toHaveAttribute('data-unread', '1');
    await item.locator('[data-action="toggle"]').click();
    await expect(item).toHaveAttribute('data-unread', '0');
    await expect(badge).toBeHidden();
    await page.keyboard.press('Escape');
    await expect(page.locator('details.nowo-notify-kit-bell')).not.toHaveAttribute('open', '');
    expect(errors.filter((e) => !/favicon|mercure|EventSource/i.test(e))).toEqual([]);
  });

  test('the inbox page lists rows and marks all read; Bob never sees the project row', async ({ page, browser }) => {
    await signIn(page, 'alice');
    await clearInbox(page);
    await send(page, 'project');
    await expect(page.locator('body')).toContainText('1 skipped (access)');
    await page.goto('/notifications');
    await expect(page.locator('[data-testid="notify-kit-inbox"] tbody tr').first()).toContainText('updated project demo');
    await page.locator('[data-testid="notify-kit-read-all"]').first().click();
    await expect(page.locator('[data-testid="notify-kit-read-all"]')).toHaveCount(0);

    const bob = await (await withoutHotReload(await browser.newContext())).newPage();
    await signIn(bob, 'bob');
    await bob.goto('/notifications');
    await expect(bob.locator('body')).not.toContainText('updated project demo');
    const operator = await bob.goto('/admin/notifications');
    expect(operator?.status()).toBe(403);
  });

  test('the badge follows a change made in another tab', async ({ page, context }) => {
    test.setTimeout(60_000);
    await signIn(page, 'alice');
    await clearInbox(page);
    await page.goto('/');
    const badge = page.locator('[data-testid="notify-kit-badge"]');
    await expect(badge).toBeHidden();

    const other = await context.newPage();
    await send(other, 'welcome');
    await expect(badge).toHaveText('1', { timeout: 25_000 });
    await expect(page.locator('[data-nowo-notify-kit-live]')).toContainText('1 unread');
  });

  test('the operator page shows counts only', async ({ page }) => {
    await signIn(page, 'alice');
    const response = await page.goto('/admin/notifications');
    expect(response?.status()).toBe(200);
    await expect(page.locator('[data-testid="notify-kit-operator"]')).toBeVisible();
    await expect(page.locator('[data-testid="notify-kit-rows"]')).toBeVisible();
    await page.locator('[data-testid="notify-kit-run-retention"]').first().click();
    await expect(page.locator('body')).toContainText('Retention');
  });

  test('the bell, its open menu and the inbox page are axe clean', async ({ page }) => {
    await signIn(page, 'alice');
    await send(page, 'welcome');
    await page.locator('[data-testid="notify-kit-bell-button"]').click();
    await expect(page.locator('.nowo-notify-kit-item').first()).toBeVisible();
    for (const target of ['bell', '/notifications', '/admin/notifications']) {
      if (target !== 'bell') {
        await page.goto(target);
      }
      const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).exclude('.sf-toolbar').analyze();
      const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
      expect(serious, target).toEqual([]);
    }
  });
});
