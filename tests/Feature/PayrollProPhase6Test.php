<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollProPhase6Test extends TestCase
{
    use RefreshDatabase;

    private function employee(string $number = 'E-100'): Employee
    {
        return Employee::create([
            'employee_number' => $number, 'first_name' => 'Jamie', 'last_name' => 'Test',
            'position' => 'Assistant', 'monthly_salary_cents' => 2000000, 'is_active' => true,
        ]);
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'label' => 'Nov 1-30', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'draft',
        ]);
    }

    public function test_cancelled_draft_keeps_history_and_releases_date_range(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $period = $this->period();
        $this->post(route('payroll-periods.cancel', $period), [
            'reason' => 'Recreate an invalid test cycle', 'confirm_cancel' => '1',
        ])->assertRedirect(route('payroll-periods.index'));
        $this->assertDatabaseHas('payroll_periods', ['id' => $period->id, 'status' => 'cancelled']);
        $this->post(route('payroll-periods.store'), [
            'label' => 'Replacement Nov', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payroll_periods', 2);
    }

    public function test_finalized_period_cannot_be_cancelled(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $period = $this->period();
        $period->update(['status' => 'finalized', 'finalized_at' => now()]);
        $this->post(route('payroll-periods.cancel', $period), [
            'reason' => 'This must not work', 'confirm_cancel' => '1',
        ])->assertSessionHasErrors('period');
        $this->assertSame('finalized', $period->fresh()->status);
    }

    public function test_cancelled_period_rejects_manual_entries_and_finalization(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $employee = $this->employee();
        $period = $this->period();
        $period->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->post(route('payroll-entries.store', $period), [
            'employee_id' => $employee->id, 'base_pay' => '1000.00', 'overtime_pay' => '0',
            'allowances' => '0', 'deductions' => '0', 'notes' => 'Documented manual test exception',
        ])->assertSessionHasErrors('period');
        $this->post(route('payroll-periods.finalize', $period))->assertSessionHasErrors('period');
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_salary_versions_and_daily_attendance_generate_only_eligible_entries(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $employee = $this->employee();
        $period = $this->period();
        $this->post(route('employees.salary-rates.store', $employee), [
            'effective_on' => '2026-11-01', 'basis' => 'daily', 'amount' => '750.25', 'reason' => 'Approved initial daily rate',
        ])->assertSessionHasNoErrors();
        $this->post(route('employees.salary-rates.store', $employee), [
            'effective_on' => '2026-11-10', 'basis' => 'daily', 'amount' => '800.00', 'reason' => 'Daily rate adjustment',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('salary_rates', 2);
        foreach ([['2026-11-05', 'present'], ['2026-11-11', 'present'], ['2026-11-13', 'leave'], ['2026-11-16', 'absent']] as [$date,$status]) {
            AttendanceRecord::create(['employee_id' => $employee->id, 'work_date' => $date, 'status' => $status]);
        }
        $this->get(route('payroll-periods.attendance-preview', $period))->assertOk()
            ->assertSee('Eligible draft');
        $this->post(route('payroll-periods.attendance-drafts', $period), ['reviewed' => '1'])
            ->assertRedirect(route('payroll-periods.show', $period));
        $entry = $period->entries()->firstOrFail();
        $this->assertSame(155025, (int) $entry->base_pay_cents);
        $this->assertSame('attendance_daily', $entry->source);
        $this->post(route('payroll-periods.attendance-drafts', $period), ['reviewed' => '1'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('payroll_entries', 1);
    }

    public function test_monthly_rates_do_not_get_automatically_prorated(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $employee = $this->employee();
        $period = $this->period();
        $this->post(route('employees.salary-rates.store', $employee), [
            'effective_on' => '2026-11-01', 'basis' => 'monthly', 'amount' => '20000.00', 'reason' => 'Monthly salary',
        ])->assertSessionHasNoErrors();
        AttendanceRecord::create(['employee_id' => $employee->id, 'work_date' => '2026-11-05', 'status' => 'present']);
        $this->get(route('payroll-periods.attendance-preview', $period))->assertSee('Monthly pay requires manual review');
        $this->post(route('payroll-periods.attendance-drafts', $period), ['reviewed' => '1'])
            ->assertSessionHasErrors('period');
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_sensitive_phase6_routes_require_login(): void
    {
        $employee = $this->employee();
        $period = $this->period();
        $this->get(route('employees.salary-rates.index', $employee))->assertRedirect(route('login'));
        $this->post(route('payroll-periods.cancel', $period), ['reason' => 'test cancel', 'confirm_cancel' => '1'])
            ->assertRedirect(route('login'));
    }
}
