const {test,expect}=require('@playwright/test');
test.describe('Payroll guidance and simulation',()=>{
  test.skip(process.env.PAYROLL_QA_ISOLATED!=='1','Requires the dedicated SQLite runner');
  test('wizard, question-mark guides and simulation work without real payroll',async({page},info)=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto('/login');await page.getByLabel('Email address').fill(process.env.PAYROLL_QA_EMAIL);await page.locator('input[name=password]').fill(process.env.PAYROLL_QA_PASSWORD);await page.locator('form[data-login-form] button[type=submit]').click();await page.waitForURL(/\/app$/);
    await page.goto('/payroll-setup');await expect(page.locator('[data-wizard-progress]')).toContainText('Step 1 of 4');
    await page.locator('[name=company_name]').fill('Example for future payroll');await page.getByLabel('When should these rules start?').fill('2027-01-01');await page.selectOption('[name=frequency]','monthly');await page.getByRole('button',{name:'Continue',exact:true}).click();
    for(const day of [1,2,3,4,5])await page.locator(`[name="workdays[]"][value="${day}"]`).check();
    await page.selectOption('[name=proration]','calendar_days');await page.selectOption('[name=monthly_attendance]','not_required');await page.selectOption('[name=paid_leave]','approved_only');await page.locator('[name=hours_per_day]').fill('8');await page.selectOption('[name=ot_approval]','required');await page.getByRole('button',{name:'Continue',exact:true}).click();
    await page.selectOption('[name=compliance]','strict');await page.selectOption('[name=contribution_timing]','monthly');await page.selectOption('[name=labor_review]','unverified');await page.selectOption('[name=separate_approver]','1');await page.getByRole('button',{name:'Continue',exact:true}).click();
    await expect(page.locator('[data-setup-summary]')).toContainText('Once a month');
    await page.locator('[name=reason]').fill('Future example confirmed by payroll lead');await page.locator('[name=setup_confirmed]').check();await page.getByRole('button',{name:'Save setup and go to payroll'}).click();await page.waitForURL(/\/payroll-periods$/);
    await page.goto('/payroll-preview?starts_on=2026-11-01&ends_on=2026-11-30');await page.getByRole('button',{name:'Preview simulated pay'}).click();
    const employee=page.locator('.payroll-simulation').filter({has:page.getByRole('heading',{name:/Nov22 Hire QA/})});await expect(employee).toContainText('60,000.00');await expect(employee).toContainText('9 paid date(s)');await expect(page.getByText('Simulation only — not a payslip or a payment.')).toBeVisible();
    await page.locator('[data-feature-help]>summary').click();await expect(page.locator('.pro-feature-help-panel')).toContainText('Practice only');await page.keyboard.press('Escape');await expect(page.locator('[data-feature-help]')).not.toHaveAttribute('open','');
    await page.goto('/my-payslips');await expect(page.getByText('Your account is not linked to an employee yet.',{exact:false})).toBeVisible();await page.locator('[data-feature-help]>summary').click();await expect(page.locator('.pro-feature-help-panel')).toContainText('Super Admin');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth)).toBeLessThanOrEqual(2);expect(errors).toEqual([]);
    await page.screenshot({path:info.outputPath('payslip-help.png'),fullPage:true});
  });
});
