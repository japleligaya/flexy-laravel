<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\Payroll\LegacySalaryBackfill;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollHistory;
use App\Services\Payroll\PayrollWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollEngineV9Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($u);

        return $u;
    }

    public static function policy(array $changes = []): array
    {
        return array_replace(['effective_on' => '2026-01-01', 'company_name' => 'Test Payroll', 'frequency' => 'monthly', 'proration' => 'calendar_days', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'hours_per_day' => 8, 'monthly_attendance' => 'not_required', 'paid_leave' => 'approved_only', 'ot_approval' => 'required', 'monthly_ot_divisor' => 30, 'contribution_timing' => 'monthly', 'compliance' => 'demo', 'labor_review' => 'ordinary_only', 'separate_approver' => false, 'reason' => 'Approved isolated payroll test policy'], $changes);
    }

    private function settings(array $changes = []): void
    {
        PayrollSetting::create(['policy' => self::policy($changes), 'created_by' => auth()->id()]);
    }

    private function employee(string $number = 'E1', string $hire = '2026-11-01', string $basis = 'monthly', int $amount = 20000000): Employee
    {
        $e = Employee::create(['employee_number' => $number, 'first_name' => 'Payroll', 'last_name' => $number, 'position' => 'Tester', 'monthly_salary_cents' => $basis === 'monthly' ? $amount : 0, 'hired_on' => $hire, 'is_active' => true]);
        $e->salaryRates()->create(['effective_on' => $hire, 'basis' => $basis, 'amount_cents' => $amount, 'reason' => 'Confirmed test initial rate', 'created_by' => auth()->id()]);

        return $e;
    }

    private function period(string $start = '2026-11-01', string $end = '2026-11-30'): PayrollPeriod
    {
        return PayrollPeriod::create(['label' => 'Test cutoff', 'starts_on' => $start, 'ends_on' => $end, 'status' => 'draft']);
    }

    private function generate(PayrollPeriod $p): void
    {
        $this->post(route('payroll-engine.generate', $p))->assertSessionHasNoErrors();
    }

    private function finalize(PayrollPeriod $p): PayrollEntry
    {
        $this->generate($p);
        foreach ($p->entries()->get() as $e) {
            $this->post(route('payroll-engine.review', $e), ['acknowledged' => 1])->assertSessionHasNoErrors();
        }
        $net = $p->entries()->get()->sum(fn ($e) => $e->net_pay_cents);
        $this->post(route('payroll-engine.transition', [$p, 'submit']), ['acknowledged' => 1, 'confirmed_net' => $net])->assertSessionHasNoErrors();
        $this->post(route('payroll-engine.transition', [$p, 'approve']))->assertSessionHasNoErrors();
        $this->post(route('payroll-periods.finalize', $p), ['acknowledged' => 1, 'confirmed_net' => $net])->assertSessionHasNoErrors();

        return $p->entries()->firstOrFail();
    }

    public function test_monthly_nov22_hire_is_automatically_prorated_without_retyping(): void
    {
        $this->admin();
        $this->settings();
        $e = $this->employee(hire: '2026-11-22');
        $p = $this->period();
        $this->generate($p);
        $entry = $p->entries()->firstOrFail();
        $this->assertSame(6000000, (int) $entry->base_pay_cents);
        $this->assertCount(9, array_filter($entry->calculation['lines'], fn ($l) => $l['type'] === 'base'));
        $this->assertEmpty($entry->calculation['exceptions']);
        $this->assertSame(6000000, array_sum(array_column(array_filter($entry->calculation['lines'], fn ($l) => $l['type'] === 'base'), 'amount_cents')));
        $this->get(route('payroll-periods.show', $p))->assertOk()->assertSee('Generate payroll for all eligible employees')->assertSee('How computed');
    }

    public function test_missing_policy_is_never_a_whole_month_salary(): void
    {
        $this->admin();
        $this->employee(hire: '2026-11-22');
        $p = $this->period();
        $this->generate($p);
        $e = $p->entries()->firstOrFail();
        $this->assertNotEmpty($e->calculation['exceptions']);
        $this->assertSame(0, (int) $e->base_pay_cents);
        $this->post(route('payroll-engine.review', $e), ['acknowledged' => 1])->assertSessionHasErrors('period');
        $this->post(route('payroll-periods.finalize', $p), ['acknowledged' => 1, 'confirmed_net' => 0])->assertSessionHasErrors('period');
    }

    public function test_monthly_rate_boundary_and_integer_rounding_are_exact(): void
    {
        $this->admin();
        $this->settings();
        $e = $this->employee(amount: 100001);
        $e->salaryRates()->create(['effective_on' => '2026-11-16', 'basis' => 'monthly', 'amount_cents' => 200003, 'reason' => 'Approved raise']);
        $c = app(PayrollEngine::class)->calculate($e, $this->period());
        $this->assertSame(150002, $c['totals']['base_pay_cents']);
        $this->assertSame(150002, array_sum(array_column(array_filter($c['lines'], fn ($l) => $l['type'] === 'base'), 'amount_cents')));
    }

    public function test_daily_rate_changes_missing_attendance_and_paid_leave_are_explicit(): void
    {
        $u = $this->admin();
        $this->settings();
        $e = $this->employee(hire: '2026-11-28', basis: 'daily', amount: 75025);
        $e->salaryRates()->create(['effective_on' => '2026-11-30', 'basis' => 'daily', 'amount_cents' => 80000, 'reason' => 'Approved raise']);
        AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => '2026-11-28', 'status' => 'present']);
        AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => '2026-11-29', 'status' => 'leave', 'paid_leave' => true, 'leave_type' => 'Approved contractual leave', 'approved_by' => $u->id, 'approved_at' => now()]);
        AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => '2026-11-30', 'status' => 'present']);
        $p = $this->period();
        $c = app(PayrollEngine::class)->calculate($e, $p);
        $this->assertSame(230050, $c['totals']['base_pay_cents']);
        $this->assertEmpty($c['exceptions']);
        AttendanceRecord::where('employee_id', $e->id)->whereDate('work_date', '2026-11-30')->delete();
        $c = app(PayrollEngine::class)->calculate($e, $p);
        $this->assertContains('attendance: missing 2026-11-30', $c['exceptions']);
    }

    public function test_unapproved_ot_excluded_then_explicit_approved_minutes_are_paid(): void
    {
        $u = $this->admin();
        $this->settings();
        $e = $this->employee(hire: '2026-11-30', basis: 'daily', amount: 80000);
        $p = $this->period();
        $a = AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => '2026-11-30', 'status' => 'present', 'overtime_minutes' => 120]);
        $c = app(PayrollEngine::class)->calculate($e, $p);
        $this->assertSame(0, $c['totals']['overtime_pay_cents']);
        $this->assertContains('Unapproved overtime excluded on 2026-11-30', $c['warnings']);
        $this->post(route('attendance.approve', $a), ['approved_overtime_minutes' => 90, 'paid_leave' => 0, 'reason' => 'Verified ordinary approved OT'])->assertSessionHasNoErrors();
        $c = app(PayrollEngine::class)->calculate($e, $p);
        $this->assertSame(18750, $c['totals']['overtime_pay_cents']);
    }

    public function test_batch_generation_is_idempotent_and_includes_daily_and_monthly(): void
    {
        $this->admin();
        $this->settings();
        $this->employee();
        $daily = $this->employee('E2', '2026-11-30', 'daily', 100000);
        AttendanceRecord::create(['employee_id' => $daily->id, 'work_date' => '2026-11-30', 'status' => 'present']);
        $this->employee('E3', '2026-12-01');
        $p = $this->period();
        $this->generate($p);
        $first = $p->entries()->pluck('calculation', 'id')->all();
        $this->generate($p);
        $this->assertDatabaseCount('payroll_entries', 2);
        $this->assertSame($first, $p->entries()->pluck('calculation', 'id')->all());
    }

    public function test_sources_changed_after_preview_cannot_overwrite_draft(): void
    {
        $this->admin();
        $this->settings();
        $e = $this->employee();
        $p = $this->period();
        $this->generate($p);
        $entry = $p->entries()->firstOrFail();
        $c = app(PayrollWorkflow::class)->candidate($entry);
        $hash = hash('sha256', json_encode($c));
        $e->salaryRates()->create(['effective_on' => '2026-11-15', 'basis' => 'monthly', 'amount_cents' => 25000000, 'reason' => 'Approved subsequent raise']);
        $this->post(route('payroll-engine.recalculate', $entry), ['preview_hash' => $hash, 'confirmed' => 1])->assertSessionHasErrors('period');
        $this->assertSame(20000000, (int) $entry->fresh()->base_pay_cents);
        $fresh = app(PayrollWorkflow::class)->candidate($entry->fresh());
        $this->post(route('payroll-engine.recalculate', $entry), ['preview_hash' => hash('sha256', json_encode($fresh)), 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertGreaterThan(20000000, (int) $entry->fresh()->base_pay_cents);
        $this->assertDatabaseHas('audit_events', ['action' => 'recalculated', 'subject_id' => $entry->id]);
    }

    public function test_reviewed_entries_are_preserved_and_require_explicit_reopen(): void
    {
        $this->admin();
        $this->settings();
        $this->employee();
        $p = $this->period();
        $this->generate($p);
        $entry = $p->entries()->firstOrFail();
        $this->post(route('payroll-engine.review', $entry), ['acknowledged' => 1])->assertSessionHasNoErrors();
        $hash = hash('sha256', json_encode(app(PayrollWorkflow::class)->candidate($entry)));
        $this->post(route('payroll-engine.recalculate', $entry), ['confirmed' => 1, 'preview_hash' => $hash])->assertSessionHasErrors('period');
        $this->post(route('payroll-engine.reopen', $entry), ['reason' => 'Verified need for recalculation'])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->reviewed_at);
    }

    public function test_completeness_blocks_finalization_and_cannot_jump_workflow(): void
    {
        $this->admin();
        $this->settings();
        $this->employee();
        $p = $this->period();
        $this->generate($p);
        $entry = $p->entries()->firstOrFail();
        $this->post(route('payroll-engine.review', $entry), ['acknowledged' => 1])->assertSessionHasNoErrors();
        $this->employee('E2');
        $this->post(route('payroll-engine.transition', [$p, 'submit']), ['acknowledged' => 1, 'confirmed_net' => 20000000])->assertSessionHasErrors('period');
        $this->post(route('payroll-periods.finalize', $p), ['acknowledged' => 1, 'confirmed_net' => 20000000])->assertSessionHasErrors('period');
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_separate_approver_is_enforced_and_rejection_clears_reviews(): void
    {
        $this->admin();
        $this->settings(['separate_approver' => true]);
        $this->employee();
        $p = $this->period();
        $this->generate($p);
        $e = $p->entries()->firstOrFail();
        $this->post(route('payroll-engine.review', $e), ['acknowledged' => 1])->assertSessionHasNoErrors();
        $this->post(route('payroll-engine.transition', [$p, 'submit']), ['acknowledged' => 1, 'confirmed_net' => $e->net_pay_cents])->assertSessionHasNoErrors();
        $this->post(route('payroll-engine.transition', [$p, 'approve']))->assertSessionHasErrors('period');
        $this->actingAs(User::factory()->create(['role' => 'approver']));
        $this->post(route('payroll-engine.transition', [$p, 'approve']))->assertSessionHasNoErrors();
        $this->post(route('payroll-engine.transition', [$p, 'reject']), ['reason' => 'Check adjustment source documents'])->assertSessionHasNoErrors();
        $this->assertNull($e->fresh()->reviewed_at);
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_snapshots_and_exports_survive_later_salary_and_employee_changes(): void
    {
        $this->admin();
        $this->settings();
        $employee = $this->employee();
        $p = $this->period();
        $e = $this->finalize($p);
        $snapshot = $e->snapshot;
        $this->assertSame('finalized', $p->fresh()->status);
        $this->assertDatabaseCount('payroll_payments', 0);
        $employee->update(['first_name' => 'Changed']);
        $employee->salaryRates()->create(['effective_on' => '2026-11-20', 'basis' => 'monthly', 'amount_cents' => 40000000, 'reason' => 'Later change preserves snapshots']);
        $this->assertSame($snapshot, $e->fresh()->snapshot);
        $csv = $this->get(route('reports.payroll.csv', $p));
        $csv->assertOk();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Payroll E1', $content);
        $this->assertStringContainsString('200000.00', $content);
        $pdf = $this->get(route('payslips.download', $e));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('200000.00', view('payslips.pdf', ['entry' => $e, 's' => $snapshot])->render());
        $this->post(route('payroll-engine.generate', $p))->assertSessionHasErrors('period');
        $this->delete(route('payroll-entries.destroy', [$p, $e]))->assertSessionHasErrors('period');
    }

    public function test_payment_is_separate_and_demo_cannot_record_real_payment(): void
    {
        $this->admin();
        $this->settings();
        $this->employee();
        $p = $this->period();
        $this->finalize($p);
        $this->post(route('payroll-engine.payment', $p), ['paid_on' => '2026-11-01', 'method' => 'cash', 'reference' => 'PAY-1', 'amount' => '200000', 'reason' => 'Actual payment confirmed', 'confirmed' => 1])->assertSessionHasErrors('period');
        $this->assertDatabaseCount('payroll_payments', 0);
        $this->assertSame('finalized', $p->fresh()->status);
    }

    public function test_manual_statutory_requires_authority_and_real_payment_reconciles(): void
    {
        $this->admin();
        $this->settings(['compliance' => 'manual']);
        $this->employee(amount: 2000000);
        $p = $this->period();
        $this->generate($p);
        $e = $p->entries()->firstOrFail();
        foreach (['sss' => ['1000', '2000'], 'philhealth' => ['500', '500'], 'pagibig' => ['200', '200'], 'bir' => ['0', '0']] as $key => [$ee,$er]) {
            $this->post(route('payroll-engine.override', $e), ['component' => $key, 'employee' => $ee, 'employer' => $er, 'reason' => 'Authorized source and classification checked for test fixture', 'source_url' => 'https://www.sss.gov.ph/'])->assertSessionHasNoErrors();
        }
        $e = $e->fresh();
        $c = app(PayrollWorkflow::class)->candidate($e);
        $this->post(route('payroll-engine.recalculate', $e), ['preview_hash' => hash('sha256', json_encode($c)), 'confirmed' => 1])->assertSessionHasNoErrors();
        $entry = $this->finalize($p);
        $this->assertSame(1830000, $entry->net_pay_cents);
        foreach ([['PAY-A', '10000'], ['PAY-B', '8300']] as [$ref,$amount]) {
            $this->post(route('payroll-engine.payment', $p), ['paid_on' => '2026-11-01', 'method' => 'cash', 'reference' => $ref, 'amount' => $amount, 'reason' => 'Confirmed test reconciliation', 'confirmed' => 1])->assertSessionHasNoErrors();
        }
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertDatabaseCount('payroll_payments', 2);
        $this->post(route('payroll-engine.payment', $p), ['paid_on' => '2026-11-01', 'method' => 'cash', 'reference' => 'PAY-C', 'amount' => '1', 'reason' => 'Must reject overpayment', 'confirmed' => 1])->assertSessionHasErrors('period');
    }

    public function test_sensitive_routes_enforce_each_role_and_own_payslip_scope(): void
    {
        $this->admin();
        $this->settings();
        $owner = $this->employee();
        $other = $this->employee('E2');
        $p = $this->period();
        $e = $this->finalize($p);
        $this->actingAs(User::factory()->create(['role' => 'employee', 'employee_id' => $owner->id]));
        foreach (['/app', '/employees', '/attendance', '/reports', '/payroll-periods', '/payroll-settings'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('payroll-engine.generate', $p))->assertForbidden();
        $this->get(route('payslips.index'))->assertOk();
        $this->get(route('payslips.download', $e))->assertOk();
        $this->get(route('payslips.download', $p->entries()->where('employee_id', $other->id)->firstOrFail()))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'hr']));
        $this->get('/employees')->assertOk();
        $this->get('/reports')->assertForbidden();
        $this->post(route('payroll-engine.generate', $p))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'payroll_officer']));
        $this->get('/payroll-periods')->assertOk();
        $this->post(route('payroll-periods.finalize', $p))->assertForbidden();
    }

    public function test_adjustments_are_effective_dated_itemized_and_negative_net_is_blocked(): void
    {
        $this->admin();
        $this->settings();
        $e = $this->employee(amount: 100000);
        $p = $this->period();
        PayrollAdjustment::create(['employee_id' => $e->id, 'type' => 'allowance', 'label' => 'Transport', 'amount_cents' => 5000, 'effective_on' => '2026-11-15', 'recurring' => false, 'taxable' => true, 'contributory' => false, 'reason' => 'Approved one off transport']);
        PayrollAdjustment::create(['employee_id' => $e->id, 'type' => 'deduction', 'label' => 'Advance', 'amount_cents' => 200000, 'effective_on' => '2026-11-15', 'recurring' => false, 'taxable' => false, 'contributory' => false, 'reason' => 'Authorized salary advance']);
        $this->generate($p);
        $entry = $p->entries()->firstOrFail();
        $this->assertSame(5000, (int) $entry->allowances_cents);
        $this->assertContains('money: deductions exceed gross (negative net)', $entry->calculation['exceptions']);
        $this->post(route('payroll-engine.review', $entry), ['acknowledged' => 1])->assertSessionHasErrors('period');
    }

    public function test_strict_statutory_never_silently_becomes_zero(): void
    {
        $this->admin();
        $this->settings(['compliance' => 'strict']);
        $e = $this->employee();
        $p = $this->period();
        $this->generate($p);
        $entry = $p->entries()->firstOrFail();
        $this->assertSame('needs_verification', $entry->calculation['statutory']['pagibig']['status']);
        $this->assertArrayNotHasKey('employee', $entry->calculation['statutory']['pagibig']);
        $this->post(route('payroll-engine.review', $entry), ['acknowledged' => 1])->assertSessionHasErrors('period');
    }

    public function test_initial_rate_is_created_once_and_missing_hire_date_is_flagged(): void
    {
        $this->admin();
        $this->settings();
        $this->post(route('employees.store'), ['employee_number' => 'N1', 'first_name' => 'New', 'last_name' => 'Hire', 'position' => 'Tester', 'monthly_salary' => '200000', 'hired_on' => '2026-11-22', 'initial_effective_on' => '2026-11-22', 'initial_basis' => 'monthly', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('salary_rates', 1);
        $e = Employee::first();
        $this->assertSame(20000000, (int) $e->salaryRates()->first()->amount_cents);
        $e->update(['hired_on' => null]);
        $c = app(PayrollEngine::class)->calculate($e, $this->period());
        $this->assertContains('employment: confirm actual hire date', $c['exceptions']);
    }

    public function test_full_and_partial_semi_monthly_cutoffs_obey_explicit_policy(): void
    {
        $this->admin();
        $this->settings(['frequency' => 'semi_monthly', 'contribution_timing' => 'split']);
        $e = $this->employee(amount: 2000000);
        $c = app(PayrollEngine::class)->calculate($e, $this->period('2026-11-01', '2026-11-15'));
        $this->assertSame(1000000, $c['totals']['base_pay_cents']);
        $this->assertEmpty($c['exceptions']);
        $c = app(PayrollEngine::class)->calculate($e, $this->period('2026-11-02', '2026-11-15'));
        $this->assertContains('cutoff: semi-monthly policy requires 1–15 or 16–month end', $c['exceptions']);
    }

    public function test_reversal_retains_original_and_requires_approval_before_replacement(): void
    {
        $this->admin();
        $this->settings();
        $this->employee();
        $p = $this->period();
        $entry = $this->finalize($p);
        $snapshot = $entry->snapshot;
        $this->post(route('payroll-engine.correction', $p), ['reason' => 'Verified full reversal request', 'confirmed' => 1])->assertSessionHasNoErrors();
        $c = PayrollCorrection::firstOrFail();
        $this->assertSame(-20000000, (int) $c->net_delta_cents);
        $this->post(route('payroll-engine.replacement', $c))->assertSessionHasErrors('period');
        $this->post(route('payroll-engine.correction-decision', $c), ['decision' => 'approved', 'reason' => 'Approved original reversal ledger', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $entry->fresh()->snapshot);
        $this->assertSame('finalized', $p->fresh()->status);
        $this->post(route('payroll-engine.replacement', $c))->assertSessionHasNoErrors();
        $replacement = PayrollPeriod::where('correction_of', $p->id)->firstOrFail();
        $this->assertTrue($replacement->starts_on->eq($p->starts_on));
        $this->generate($replacement);
        $this->post(route('payroll-engine.replacement', $c))->assertSessionHasErrors('period');
        $this->post(route('payroll-engine.correction', $p), ['reason' => 'Duplicate reversal must reject', 'confirmed' => 1])->assertSessionHasErrors('period');
        $this->get(route('payroll-periods.show', $p))->assertOk()->assertSee('Reversal #');
    }

    public function test_salary_history_and_final_snapshots_reject_model_edits(): void
    {
        $this->admin();
        $this->settings();
        $employee = $this->employee();
        $rate = $employee->salaryRates()->firstOrFail();
        try {
            $rate->update(['amount_cents' => 1]);
            $this->fail('Historical rate changed');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('history', $ex->errors());
        }
        $p = $this->period();
        $entry = $this->finalize($p);
        try {
            $entry->update(['base_pay_cents' => 1]);
            $this->fail('Finalized money changed');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('period', $ex->errors());
        }
        $this->assertSame(20000000, (int) $entry->fresh()->base_pay_cents);
    }

    public function test_policy_screen_renders_for_admin_and_policy_save_requires_all_choices(): void
    {
        $this->admin();
        $this->employee();
        $this->get(route('payroll-settings.index'))->assertOk()->assertSee('Official statutory rules');
        $this->post(route('payroll-settings.store'), ['company_name' => 'Insufficient policy'])->assertSessionHasErrors(['frequency', 'proration', 'workdays', 'compliance']);
        $this->post(route('payroll-settings.store'), self::policy())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payroll_settings', 1);
    }

    public function test_approved_leave_and_leaving_dates_reduce_salary_with_explicit_divisor(): void
    {
        $u = $this->admin();
        $this->settings(['proration' => 'scheduled_workdays', 'workdays' => [1, 2, 3, 4, 5]]);
        $e = $this->employee(hire: '2026-11-02', amount: 2100000);
        $e->update(['ended_on' => '2026-11-06', 'is_active' => false]);
        // November 2026 has 21 Monday–Friday days. Five employment days, one unpaid.
        AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => '2026-11-03', 'status' => 'absent']);
        AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => '2026-11-04', 'status' => 'leave', 'paid_leave' => 1, 'approved_by' => $u->id, 'approved_at' => now(), 'leave_type' => 'Contractual paid leave']);
        $p = $this->period();
        $c = app(PayrollEngine::class)->calculate($e, $p);
        $this->assertSame(400000, $c['totals']['base_pay_cents']);
        $this->assertEmpty($c['exceptions']);
        $this->generate($p);
        $this->assertDatabaseCount('payroll_entries', 1);
    }

    public function test_backfill_is_conservative_idempotent_and_preserves_existing_rates(): void
    {
        $this->admin();
        $data = ['first_name' => 'Legacy', 'last_name' => 'Payroll', 'position' => 'Tester', 'monthly_salary_cents' => 2000000, 'is_active' => true];
        $dated = Employee::create($data + ['employee_number' => 'L1', 'hired_on' => '2020-04-09']);
        $undated = Employee::create($data + ['employee_number' => 'L2']);
        $versioned = $this->employee('V1', '2021-01-01', amount: 3000000);
        $backfill = app(LegacySalaryBackfill::class);
        $this->assertSame(['created' => 1, 'needs_effective_date' => 1, 'existing_history' => 1], $backfill->run());
        $this->assertSame(0, $backfill->run()['created']);
        $this->assertSame('2020-04-09', $dated->salaryRates()->first()->effective_on->toDateString());
        $this->assertSame(0, $undated->salaryRates()->count());
        $this->assertSame(1, $versioned->salaryRates()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'rate_backfilled']);
    }

    public function test_settings_salary_attendance_and_recalculation_views_render(): void
    {
        $this->admin();
        $this->settings();
        $employee = $this->employee();
        $p = $this->period();
        $this->generate($p);
        AttendanceRecord::create(['employee_id' => $employee->id, 'work_date' => '2026-11-01', 'status' => 'present', 'overtime_minutes' => 60]);
        foreach ([route('payroll-settings.index'), route('employees.edit', $employee), route('employees.salary-rates.index', $employee), route('attendance.index'), route('payroll-engine.preview', $p->entries()->first())] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_company_policy_is_effective_dated_and_unknown_history_is_gated(): void
    {
        $this->admin();
        $this->settings();
        $e = $this->employee(amount: 3000000);
        $p = $this->period();
        $this->settings(['effective_on' => '2026-12-01', 'proration' => 'scheduled_workdays']);
        $c = app(PayrollEngine::class)->calculate($e, $p);
        $this->assertSame('calendar_days', $c['policy']['proration']);
        $this->assertSame('2026-01-01', $c['policy']['effective_on']);
        $this->assertSame([], PayrollSetting::forDate('2025-12-31'));
    }

    public function test_semi_monthly_contribution_splits_reconcile_employee_employer_and_ec(): void
    {
        $this->admin();
        $this->settings(['compliance' => 'manual', 'frequency' => 'semi_monthly', 'contribution_timing' => 'split']);
        $employee = $this->employee(hire: '2026-01-01', amount: 3000000);
        $employee->update(['payroll_classification' => 'standard_private_regular']);
        $first = $this->period('2026-10-01', '2026-10-15');
        $this->generate($first);
        $entry = $first->entries()->firstOrFail();
        foreach (['pagibig' => ['100', '100'], 'bir' => ['0', '0']] as $key => [$ee,$er]) {
            $this->post(route('payroll-engine.override', $entry), ['component' => $key, 'employee' => $ee, 'employer' => $er, 'reason' => 'Authorized fixture from verified employee file', 'source_url' => 'https://www.pagibigfund.gov.ph/Membership_RegularSavings.html'])->assertSessionHasNoErrors();
        }
        $candidate = app(PayrollWorkflow::class)->candidate($entry->fresh());
        $this->post(route('payroll-engine.recalculate', $entry), ['preview_hash' => hash('sha256', json_encode($candidate)), 'confirmed' => 1])->assertSessionHasNoErrors();
        $one = $this->finalize($first)->snapshot['calculation']['statutory'];
        $second = $this->period('2026-10-16', '2026-10-31');
        $two = app(PayrollEngine::class)->calculate($employee, $second)['statutory'];
        $this->assertSame(150000, $one['sss']['employee'] + $two['sss']['employee']);
        $this->assertSame(300000, $one['sss']['employer'] + $two['sss']['employer']);
        $this->assertSame(3000, $one['sss']['ec'] + $two['sss']['ec']);
        $this->assertSame(75000, $one['philhealth']['employee'] + $two['philhealth']['employee']);
        $this->assertSame(75000, $one['philhealth']['employer'] + $two['philhealth']['employer']);
    }

    public function test_ytd_history_excludes_demo_and_keeps_unknown_legacy_amounts_null(): void
    {
        $this->admin();
        $this->settings();
        $employee = $this->employee(hire: '2026-01-01');
        $this->finalize($this->period('2026-10-01', '2026-10-31'));
        $period = $this->period();
        $history = app(PayrollHistory::class)->summarize($employee, $period);
        $this->assertSame(1, $history['ytd']['excluded_demo_count']);
        $this->assertSame(0, $history['ytd']['gross_cents']);
        $legacy = PayrollPeriod::create(['label' => 'Legacy September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'finalized']);
        $legacy->entries()->create(['employee_id' => $employee->id, 'base_pay_cents' => 100000, 'source' => 'manual']);
        $history = app(PayrollHistory::class)->summarize($employee, $period);
        $this->assertSame(1, $history['ytd']['unverified_legacy_count']);
        $this->assertNull($history['ytd']['gross_cents']);
        $this->assertNull($history['ytd']['taxable_cents']);
        $this->assertNull($history['ytd']['contributions']['sss']['employee']);
    }

    public function test_dated_adjustments_do_not_pay_outside_employment(): void
    {
        $this->admin();
        $this->settings();
        $employee = $this->employee(hire: '2026-11-22');
        foreach ([['Early bonus', '2026-11-10', null, false, 100000], ['Expired allowance', '2026-11-01', '2026-11-10', true, 200000], ['Valid bonus', '2026-11-25', null, false, 1000]] as [$label,$start,$end,$recurring,$amount]) {
            PayrollAdjustment::create(['employee_id' => $employee->id, 'type' => 'bonus', 'label' => $label, 'amount_cents' => $amount, 'effective_on' => $start, 'ends_on' => $end, 'recurring' => $recurring, 'taxable' => true, 'contributory' => true, 'reason' => 'Explicitly authorized dated test adjustment']);
        }
        $c = app(PayrollEngine::class)->calculate($employee, $this->period());
        $this->assertSame(1000, $c['totals']['allowances_cents']);
        $this->assertCount(2, array_filter($c['warnings'], fn ($w) => str_contains($w, 'do not overlap employment')));
    }

    public function test_special_premium_amounts_require_documented_official_sources(): void
    {
        $this->admin();
        $this->settings();
        $employee = $this->employee();
        foreach (['holiday', 'night', 'thirteenth'] as $type) {
            PayrollAdjustment::create(['employee_id' => $employee->id, 'type' => $type, 'label' => $type, 'amount_cents' => 10000, 'effective_on' => '2026-11-15', 'recurring' => false, 'taxable' => false, 'contributory' => false, 'reason' => 'Premium requires verified coverage and tax treatment']);
        }
        $c = app(PayrollEngine::class)->calculate($employee, $this->period());
        $this->assertCount(3, array_filter($c['exceptions'], fn ($e) => str_starts_with($e, 'labor: official source')));
    }

    public function test_company_policy_rejects_mid_month_changes_that_cannot_form_a_complete_cutoff(): void
    {
        $this->admin();
        $this->post(route('payroll-settings.store'), self::policy(['effective_on' => '2026-11-22']))->assertSessionHasErrors('effective_on');
        $this->assertDatabaseCount('payroll_settings', 0);
    }

    public function test_ready_batch_acknowledgement_preserves_blocking_exceptions(): void
    {
        $this->admin();
        $this->settings();
        $this->employee();
        $this->employee('D2', '2026-11-30', 'daily', 100000);
        $p = $this->period();
        $this->generate($p);
        $this->post(route('payroll-engine.review-ready', $p), ['acknowledged' => 1, 'confirmed_count' => 1, 'confirmed_net' => 20000000])->assertSessionHasNoErrors();
        $this->assertSame(1, $p->entries()->whereNotNull('reviewed_at')->count());
        $this->assertSame(1, $p->entries()->whereNull('reviewed_at')->count());
        $this->post(route('payroll-engine.review-ready', $p), ['acknowledged' => 1, 'confirmed_count' => 1, 'confirmed_net' => 20000000])->assertSessionHasErrors('period');
    }
}
