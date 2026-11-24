<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        $e=Employee::create(['employee_number'=>'UX1','first_name'=>'New','last_name'=>'Employee','position'=>'Tester','hired_on'=>'2026-11-22','is_active'=>true,'monthly_salary_cents'=>20000000]);
        $e->salaryRates()->create(['effective_on'=>'2026-11-22','basis'=>'monthly','amount_cents'=>20000000,'reason'=>'Confirmed initial salary']);
        return $e;
    }

    private function policy(): array
    {
        return ['company_name'=>'Confirmed company','effective_on'=>'2026-11-01','frequency'=>'monthly','proration'=>'calendar_days','workdays'=>[1,2,3,4,5],'hours_per_day'=>8,'monthly_attendance'=>'not_required','paid_leave'=>'approved_only','ot_approval'=>'required','contribution_timing'=>'monthly','compliance'=>'strict','labor_review'=>'unverified','separate_approver'=>'1','reason'=>'Company choices confirmed by payroll lead'];
    }

    public function test_missing_information_is_grouped_and_incomplete_net_is_not_shown_as_payable(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'super_admin']));
        $employee=$this->employee();
        $p=PayrollPeriod::create(['label'=>'November','starts_on'=>'2026-11-01','ends_on'=>'2026-11-30','status'=>'draft']);
        $calc=app(PayrollEngine::class)->calculate($employee,$p);
        $p->entries()->create(['employee_id'=>$employee->id,'base_pay_cents'=>10000000,'source'=>'manual','calculation'=>$calc]);
        $html=$this->get(route('payroll-periods.show',$p))->assertOk()->assertSee('Needs Setup')->assertSee('Needs Compliance Review')->assertSee('Complete payroll setup')->getContent();
        $dom=new \DOMDocument();@$dom->loadHTML($html);
        $xpath=new \DOMXPath($dom);
        $net=$xpath->query('//dt[contains(text(),"Estimated net pay")]/following-sibling::dd')->item(0);
        $this->assertNotNull($net);$this->assertStringContainsString('Not available yet',$net->textContent);
        foreach(iterator_to_array($xpath->query('//details[contains(@class,"payroll-technical")]')) as $technical)$technical->parentNode->removeChild($technical);
        $this->assertStringNotContainsString('policy:frequency',$dom->textContent);
        $this->assertStringNotContainsString('statutory:sss',$dom->textContent);
        $this->assertSame('draft',$p->fresh()->status);
    }

    public function test_simulation_uses_existing_salary_without_writing_policy_payroll_or_audit(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'payroll_officer']));$this->employee();
        PayrollSetting::create(['policy'=>$this->policy()]);
        $auditCount=DB::table('audit_events')->count();$policy=PayrollSetting::current();
        $this->get(route('payroll-preview',['preview'=>1,'starts_on'=>'2026-11-01','ends_on'=>'2026-11-30','frequency'=>'monthly','proration'=>'calendar_days','workdays'=>[1,2,3,4,5],'monthly_attendance'=>'not_required','hours_per_day'=>8]))
            ->assertOk()->assertSee('Simulation only')->assertSee('60,000.00')->assertSee('9 paid date(s)')->assertSee('Government deductions are excluded');
        $this->assertDatabaseCount('payroll_entries',0);$this->assertDatabaseCount('payroll_periods',0);$this->assertDatabaseCount('payroll_payments',0);
        $this->assertDatabaseCount('payroll_settings',1);$this->assertSame($policy,PayrollSetting::current());$this->assertSame($auditCount,DB::table('audit_events')->count());
    }

    public function test_setup_requires_authorization_and_confirmation_and_payslip_help_explains_account_link(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'employee']));
        $this->get(route('payroll-setup'))->assertForbidden();$this->get(route('payroll-preview'))->assertForbidden();
        $this->get(route('payslips.index'))->assertOk()->assertSee('Your account is not linked')->assertSee('Help: My payslips');
        $this->actingAs(User::factory()->create(['role'=>'super_admin']));
        $this->get(route('payroll-setup'))->assertOk()->assertSee('Workdays and partial months')->assertSee('Check and confirm your choices');
        $data=$this->policy()+['setup_wizard'=>1];
        $this->post(route('payroll-settings.store'),$data)->assertSessionHasErrors('setup_confirmed');$this->assertDatabaseCount('payroll_settings',0);
        $this->post(route('payroll-settings.store'),$data+['setup_confirmed'=>1])->assertRedirect(route('payroll-periods.index'));
        $this->assertDatabaseCount('payroll_settings',1);$this->assertSame('strict',PayrollSetting::current()['compliance']);$this->assertTrue(PayrollSetting::current()['separate_approver']);
    }
}
