import { test, expect, type Locator, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * REQ-DEMO-013 — the S3 flows (contract §8.3, §14 "UI"): autosave of the matrix, the master switch and a project
 * switch; the optional bell of mentions honouring the preference; a stale save from a second tab (409, the page
 * shows the current value); the context dialog loaded on demand; Bob cannot open Alice's project (404); axe; the
 * script-loaded console line (REQ-ASSETS-005). Fails on console errors.
 */
async function signIn(page: Page, person: 'alice' | 'bob'): Promise<void> {
  await page.goto('/');
  if (await page.locator('[data-testid="notify-kit-bell"]').count()) {
    await page.goto('/logout');
  }
  await page.locator(`[data-testid="demo-sign-in"] input[value="${person}"]`).check();
  await page.locator('[data-testid="demo-sign-in"] [type="submit"]').first().click();
  await expect(page.locator('[data-testid="notify-kit-bell"]')).toBeVisible();
}

async function setCell(page: Page, testid: string, checked: boolean, scope?: Locator): Promise<void> {
  const root = scope ?? page.locator('body');
  const saved = page.waitForResponse((r) => r.url().endsWith('/account/notifications/cells'));
  await root.locator(`[data-testid="${testid}"]`).setChecked(checked);
  expect((await saved).status()).toBe(200);
  await expect(root.locator('[data-nowo-notify-kit-status]').first()).toHaveText('Saved');
}

async function reset(page: Page): Promise<void> {
  await page.goto('/account/notifications');
  for (const testid of ['notify-kit-master', 'notify-kit-cell-mentions-in_app', 'notify-kit-cell-account-mail', 'notify-kit-context-0']) {
    if (!(await page.locator(`[data-testid="${testid}"]`).isChecked())) {
      await setCell(page, testid, true);
    }
  }
}

test.describe('NotifyKitBundle S3: preferences', () => {
  test('autosave stores a cell, the bell of mentions follows it, and a reload shows it', async ({ page }) => {
    const errors: string[] = [];
    const logs: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') errors.push(m.text());
      if (m.type() === 'log') logs.push(m.text());
    });
    await signIn(page, 'alice');
    await reset(page);
    await expect(page.locator('[data-nowo-notify-kit-submit]')).toBeHidden();
    await expect(page.locator('[data-testid="notify-kit-locked-account-in_app"]')).toContainText('Always on');

    await setCell(page, 'notify-kit-cell-mentions-in_app', false);
    await page.goto('/');
    await page.locator('[data-testid="demo-notify"] select').selectOption('mention');
    await page.locator('[data-testid="demo-notify"] [type="submit"]').first().click();
    await expect(page.locator('body')).toContainText('1 skipped (preferences)');

    await page.goto('/account/notifications');
    await expect(page.locator('[data-testid="notify-kit-cell-mentions-in_app"]')).not.toBeChecked();
    await setCell(page, 'notify-kit-cell-mentions-in_app', true);
    expect(logs.some((line) => /\[notify-kit\/preferences\] script loaded, build: \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC/.test(line))).toBe(true);
    expect(errors.filter((e) => !/favicon|mercure|EventSource/i.test(e))).toEqual([]);
  });

  test('a stale change from a second tab is refused and the page shows the current value', async ({ page, context }) => {
    await signIn(page, 'alice');
    await reset(page);
    const other = await context.newPage();
    await other.goto('/account/notifications');
    await setCell(page, 'notify-kit-cell-account-mail', false);

    // The second tab still shows mail on: its change of that cell is stale.
    const answer = other.waitForResponse((r) => r.url().endsWith('/account/notifications/cells'));
    await other.locator('[data-testid="notify-kit-cell-account-mail"]').setChecked(false);
    expect((await answer).status()).toBe(409);
    await expect(other.locator('[data-nowo-notify-kit-status]').first()).toContainText('changed in another window');
    await expect(other.locator('[data-testid="notify-kit-cell-account-mail"]')).not.toBeChecked();
    await setCell(other, 'notify-kit-cell-account-mail', true);
  });

  test('the project dialog is loaded on demand, autosaves its override and closes', async ({ page }) => {
    await signIn(page, 'alice');
    await reset(page);
    await expect(page.locator('dialog')).toHaveCount(0);
    await page.locator('[data-testid="notify-kit-override-0"]').click();
    const dialog = page.locator('dialog.nowo-notify-kit-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('h1')).toHaveText('Notifications for Demo project');
    const axe = await new AxeBuilder({ page }).include('dialog').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([]);
    await setCell(page, 'notify-kit-cell-projects-mail', true, dialog);
    await page.keyboard.press('Escape');
    await expect(page.locator('dialog')).toHaveCount(0);

    await page.goto('/account/notifications/context?key=project:demo');
    await expect(page.locator('[data-testid="notify-kit-cell-projects-mail"]')).toBeChecked();
    await page.locator('[data-testid="notify-kit-preferences-reset"]').first().click();
    await expect(page.locator('[data-testid="notify-kit-cell-projects-mail"]')).not.toBeChecked();
  });

  test('Bob cannot open Alice\'s project; the page is axe clean', async ({ page }) => {
    await signIn(page, 'bob');
    const denied = await page.goto('/account/notifications/context?key=project:demo');
    expect(denied?.status()).toBe(404);
    await page.goto('/account/notifications');
    await expect(page.locator('[data-testid="notify-kit-contexts"]')).toContainText('Sandbox');
    await expect(page.locator('[data-testid="notify-kit-contexts"]')).not.toContainText('Demo project');
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).exclude('.sf-toolbar').analyze();
    expect(result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  });
});
