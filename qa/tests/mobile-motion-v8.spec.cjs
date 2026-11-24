const {test,expect} = require('@playwright/test');

test.describe('Mobile motion V8',()=>{
  test('touch-capable viewport has active motion on controls',async({page})=>{
    await page.emulateMedia({reducedMotion:'no-preference'});
    await page.goto('/');
    const link=page.locator('.pl-hero-actions a.pl-button').first();
    await expect(link).toBeVisible();
    await expect(page.locator('link[href*="mobile-motion-v8.css"]')).toHaveCount(1);
    const duration=await link.evaluate(el=>getComputedStyle(el).transitionDuration);
    expect(duration).not.toBe('0s');
    expect(duration).not.toBe('1e-05s');
  });
  test('reduced motion disables moving effects',async({page})=>{
    await page.emulateMedia({reducedMotion:'reduce'});
    await page.goto('/');
    const el=page.locator('.pl-hero-actions a.pl-button').first();
    const duration=await el.evaluate(node=>getComputedStyle(node).transitionDuration);
    expect(duration==='0s'||duration.includes('1e-05s')||duration.includes('0.00001s')).toBe(true);
  });
});
