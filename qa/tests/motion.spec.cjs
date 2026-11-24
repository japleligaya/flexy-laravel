const { test, expect } = require('@playwright/test');

test('motion assets are loaded on public and login pages', async ({ page }) => {
  for (const url of ['/', '/login']) {
    await page.goto(url);
    await expect(page.locator('link[href*="motion-polish.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="motion-polish.js"]')).toHaveCount(1);
    await expect(page.locator('link[href*="qa-polish.css"]')).toHaveCount(1);
  }
});

test('button press feedback works without changing navigation or input caret', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.goto('/');
  const action = page.locator('.pl-hero-actions .pl-button-primary').first();
  await expect(action).toBeVisible();
  const elementTransition = await action.evaluate(el => getComputedStyle(el).transitionDuration);
  expect(elementTransition).not.toBe('0s');
  await action.dispatchEvent('pointerdown', { button: 0, clientX: 30, clientY: 30, isPrimary: true });
  await expect(action.locator('.pro-motion-wave')).toHaveCount(1);
  await page.goto('/login');
  const email = page.locator('input[type="email"]').first();
  await email.fill('testing@example.com');
  await expect(email).toHaveValue('testing@example.com');
  expect(await email.evaluate(el => getComputedStyle(el).caretColor)).not.toBe('rgba(0, 0, 0, 0)');
});

test('reduced-motion preference disables decorative press waves', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/');
  const action = page.locator('.pl-hero-actions .pl-button-primary').first();
  await action.dispatchEvent('pointerdown', { button: 0, clientX: 30, clientY: 30, isPrimary: true });
  await expect(action.locator('.pro-motion-wave')).toHaveCount(0);
});
