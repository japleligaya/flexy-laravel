const { test, expect } = require('@playwright/test');
const email = process.env.PAYROLL_QA_EMAIL;
const password = process.env.PAYROLL_QA_PASSWORD;

test.describe('Authenticated UI (optional)', () => {
  test.skip(!email || !password, 'Provide PAYROLL_QA_EMAIL and PAYROLL_QA_PASSWORD for read-only admin UI tests');

  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('form[data-login-form] button[type="submit"]').click();
    await page.waitForURL(/\/app(?:\?|$)/);
  });

  test('all module pages render safely with no page errors', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    const routes = ['/app', '/employees', '/departments', '/attendance', '/payroll-periods', '/reports'];
    for (const route of routes) {
      await page.goto(route, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('#main-content')).toBeVisible();
      expect(new URL(page.url()).pathname).toBe(route);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `Horizontal overflow on ${route}`).toBeLessThanOrEqual(2);
    }
    expect(pageErrors).toEqual([]);
  });

  test('sidebar navigation and history keep the right page', async ({ page }) => {
    const first = page.locator('#sidebarnav a[href*="employees"]').first();
    if (!(await first.isVisible()) || (await first.boundingBox())?.x < 0) await page.locator('.topbar .sidebartoggler').first().click();
    await first.click();
    await page.waitForURL(/\/employees(?:\?|$)/);
    await expect(page.locator('#main-content')).toBeVisible();
    await page.goBack();
    await page.waitForURL(/\/app(?:\?|$)/);
    await expect(page.locator('#main-content')).toBeVisible();
  });

  test('noneditable text has no caret, employee search remains editable', async ({ page }) => {
    await page.goto('/employees');
    const bodyCaret = await page.locator('body').evaluate(el => getComputedStyle(el).caretColor);
    expect(bodyCaret).toBe('rgba(0, 0, 0, 0)');
    const input = page.locator('input:not([type="hidden"]):not([type="checkbox"])').first();
    if (await input.count()) {
      await input.focus();
      const caret = await input.evaluate(el => getComputedStyle(el).caretColor);
      expect(caret).not.toBe('rgba(0, 0, 0, 0)');
    }
    await page.screenshot({ path: test.info().outputPath('employees-review.png'), fullPage: true });
  });
});
