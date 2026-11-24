const { test, expect } = require('@playwright/test');


// On mobile the navigation is collapsed by design. Open it before accessing
// the same appearance control used on desktop.
async function themeSelector(page) {
  const selector = page.locator('[data-theme-select]').first();
  if (!(await selector.isVisible())) {
    const menuButton = page.locator('[data-menu-toggle]').first();
    await expect(menuButton).toBeVisible();
    await menuButton.click();
    await expect(menuButton).toHaveAttribute('aria-expanded', 'true');
  }
  await expect(selector).toBeVisible();
  return selector;
}

async function assertFitsViewport(page) {
  const { excess, width, scrollWidth } = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const scrollWidth = document.documentElement.scrollWidth;
    return { width, scrollWidth, excess: scrollWidth - width };
  });
  expect(excess, `Horizontal overflow ${excess}px (${scrollWidth} vs ${width})`).toBeLessThanOrEqual(2);
}

test('landing and login render without horizontal overflow', async ({ page }) => {
  for (const path of ['/', '/login']) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response.status()).toBeLessThan(400);
    await expect(page.locator('body')).toBeVisible();
    await assertFitsViewport(page);
  }
});

test('theme choice persists between landing and login', async ({ page }) => {
  await page.goto('/');
  const selector = await themeSelector(page);
  await selector.selectOption('dark');
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  await page.goto('/login');
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  await (await themeSelector(page)).selectOption('light');
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'light');
});

test('system theme follows OS appearance', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto('/');
  await (await themeSelector(page)).selectOption('system');
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  await page.emulateMedia({ colorScheme: 'light' });
  await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'light');
});

test('clicking ordinary text does not produce an editable CSS caret', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('link[href*="qa-polish.css"]')).toHaveCount(1);
  const bodyCaret = await page.locator('body').evaluate(el => getComputedStyle(el).caretColor);
  expect(bodyCaret).toBe('rgba(0, 0, 0, 0)');
  await page.goto('/login');
  const input = page.locator('input[type="email"]').first();
  await input.fill('example@example.test');
  await expect(input).toHaveValue('example@example.test');
  const caret = await input.evaluate(el => getComputedStyle(el).caretColor);
  expect(caret).not.toBe('rgba(0, 0, 0, 0)');
});

test('public page controls and preview are interactive', async ({ page }) => {
  await page.goto('/');
  const tabs = page.locator('[data-showcase-tab]');
  if (await tabs.count()) {
    const tab = tabs.filter({ hasText: 'Employees' }).first();
    if (await tab.count()) {
      await tab.click();
      await expect(tab).toHaveAttribute('aria-selected', 'true');
    }
  }
  const details = page.locator('details').first();
  if (await details.count()) {
    await details.locator('summary').click();
    await expect(details).toHaveAttribute('open', '');
  }
  // Save screenshots for human comparison, without creating a flaky pixel baseline.
  await page.screenshot({ path: test.info().outputPath('landing-review.png'), fullPage: true });
});

test('stylesheet and script requests do not 404', async ({ page }) => {
  const missing = [];
  page.on('response', response => {
    const request = response.request();
    const type = request.resourceType();
    if (['stylesheet', 'script', 'font'].includes(type) && response.status() >= 400) {
      missing.push(`${response.status()} ${response.url()}`);
    }
  });
  await page.goto('/', {waitUntil: 'networkidle'});
  await page.goto('/login', {waitUntil: 'networkidle'});
  expect(missing, `Missing CSS, JS or font files: ${missing.join('\n')}`).toEqual([]);
});
