const {test,expect}=require('@playwright/test');
test.describe('V9 isolated payroll workflow',()=>{
  test.skip(process.env.PAYROLL_QA_ISOLATED!=='1','Financial UI tests require node qa/run-isolated.cjs (dedicated SQLite only)');
  test('generate, review, approve, finalize and download locked payslip',async({page},info)=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto('/login');await page.getByLabel('Email address').fill(process.env.PAYROLL_QA_EMAIL);await page.locator('input[name=password]').fill(process.env.PAYROLL_QA_PASSWORD);await page.locator('form[data-login-form] button[type=submit]').click();await page.waitForURL(/\/app$/);
    const mobile=info.project.name.startsWith('mobile');const period=mobile?2:1;const expected=mobile?2:3;
    await page.goto(`/payroll-periods/${period}`);
    await page.getByRole('button',{name:'Generate payroll for all eligible employees'}).click();
    await expect(page.locator('[data-entry]')).toHaveCount(expected);
    const token=await page.locator('input[name=_token]').first().inputValue();
    await Promise.all([page.request.post(`/payroll-periods/${period}/generate`,{form:{_token:token}}),page.request.post(`/payroll-periods/${period}/generate`,{form:{_token:token}})]);
    await page.reload();await expect(page.locator('[data-entry]')).toHaveCount(expected);
    if(!mobile){const hire=page.locator('[data-entry]').filter({has:page.getByRole('heading',{name:'Nov22 Hire QA'})});await expect(hire).toContainText('60,000.00');}
    await page.locator('[data-entry] summary').filter({hasText:'How computed'}).first().click();await expect(page.locator('.payroll-lines').first()).toBeVisible();
    await page.selectOption('#readiness','needs-review');await expect(page.locator('[data-entry]')).toHaveCount(0);await page.selectOption('#readiness','all');await expect(page.locator('[data-entry]')).toHaveCount(expected);
    const ids=await page.locator('[data-entry]').evaluateAll(nodes=>nodes.map(n=>n.dataset.entry));
    for(const id of ids){let card=page.locator(`[data-entry="${id}"]`);await card.locator('input[name=acknowledged]').check();await card.getByRole('button',{name:'Mark reviewed',exact:true}).click();await expect(page.locator(`[data-entry="${id}"]`)).toContainText('Reviewed');}
    const submit=page.locator('form[action$="/workflow/submit"]');await submit.locator('input[name=acknowledged]').check();await submit.getByRole('button').click();
    await page.getByRole('button',{name:'Approve reviewed payroll',exact:true}).click();
    page.once('dialog',d=>d.accept());const final=page.locator('form[action$="/finalize"]');await final.locator('input[name=acknowledged]').check();await final.getByRole('button').click();
    await expect(page.locator('.payroll-state')).toHaveText('Finalized');await expect(page.getByText('Finalization does not mark Paid.',{exact:false})).toBeVisible();
    const pdf=await page.request.get(`/payslips/${ids[0]}/pdf`);expect(pdf.status()).toBe(200);expect((await pdf.body()).subarray(0,5).toString()).toBe('%PDF-');
    const csv=await page.request.get(`/reports/payroll/${period}/csv`);expect(csv.status()).toBe(200);expect(await csv.text()).toContain('DEMO');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth)).toBeLessThanOrEqual(2);
    await page.screenshot({path:info.outputPath('payroll-finalized.png'),fullPage:true});expect(errors).toEqual([]);
  });
});
