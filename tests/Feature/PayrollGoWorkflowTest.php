<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollGoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        // The public landing page is accessible to guests.
        $this->get('/')->assertOk();

        // Protected modules require authentication.
        $this->get('/employees')->assertRedirect('/login');
        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_employee_creation_and_search(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $dept = Department::create(['name' => 'Engineering']);
        $this->post(route('employees.store'), [
            'employee_number' => 'EMP-001', 'first_name' => 'Alex', 'last_name' => 'Reyes',
            'email' => 'alex@example.test', 'department_id' => $dept->id,
            'position' => 'Developer', 'monthly_salary' => '25000.50', 'hired_on' => '2026-11-01',
            'is_active' => '1',
        ])->assertRedirect(route('employees.index'));
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-001', 'monthly_salary_cents' => 2500050]);
        $this->get(route('employees.index', ['q' => 'Reyes']))->assertSee('Alex Reyes');
    }

    public function test_payroll_entry_calculation_and_finalization_lock(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $employee = Employee::create([
            'employee_number' => 'EMP-002', 'first_name' => 'Sam', 'last_name' => 'Cruz',
            'position' => 'Analyst', 'monthly_salary_cents' => 3000000, 'is_active' => true,
        ]);
        $period = PayrollPeriod::create([
            'label' => 'Nov 1-15', 'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-15', 'status' => 'draft',
        ]);
        $this->post(route('payroll-entries.store', $period), [
            'employee_id' => $employee->id, 'base_pay' => '15000.00',
            'overtime_pay' => '1000.50', 'allowances' => '500.00',
            'deductions' => '2000.25', 'notes' => 'Documented manual calculation test',
        ])->assertSessionHasNoErrors();
        $entry = $period->entries()->firstOrFail();
        $this->assertSame(1650050, $entry->gross_pay_cents);
        $this->assertSame(1450025, $entry->net_pay_cents);
        $this->post(route('payroll-periods.finalize', $period))->assertSessionHasErrors('period');
        // Unverified legacy totals cannot bypass review/approval. Lock fixture for edit protection.
        $period->update(['status' => 'finalized', 'finalized_at' => now()]);
        $this->assertSame('finalized', $period->fresh()->status);
        $this->delete(route('payroll-entries.destroy', [$period, $entry]))->assertSessionHasErrors('period');
        $this->assertDatabaseHas('payroll_entries', ['id' => $entry->id]);
    }

    public function test_deductions_cannot_exceed_gross_and_duplicate_attendance_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $employee = Employee::create([
            'employee_number' => 'EMP-003', 'first_name' => 'Test', 'last_name' => 'Person',
            'position' => 'Assistant', 'monthly_salary_cents' => 1000000, 'is_active' => true,
        ]);
        $period = PayrollPeriod::create([
            'label' => 'Late November', 'starts_on' => '2026-11-16',
            'ends_on' => '2026-11-30', 'status' => 'draft',
        ]);
        $this->post(route('payroll-entries.store', $period), [
            'employee_id' => $employee->id, 'base_pay' => '200.00',
            'overtime_pay' => '0', 'allowances' => '0', 'deductions' => '300.00', 'notes' => 'Documented invalid deduction test',
        ])->assertSessionHasErrors('deductions');
        $this->assertDatabaseCount('payroll_entries', 0);
        $payload = ['employee_id' => $employee->id, 'work_date' => '2026-11-22', 'status' => 'present'];
        $this->post(route('attendance.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('attendance.store'), $payload)->assertSessionHasErrors('work_date');
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_money_uses_integer_centavos(): void
    {
        $this->assertSame(150001, Money::toCents('1500.01'));
        $this->assertSame('1500.01', Money::input(150001));
        $this->assertSame('₱1,500.01', Money::display(150001));
    }
}
