<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PayrollQaFixtures extends Command
{
    protected $signature = 'payroll:qa-fixtures';

    protected $description = 'Seed demo browser fixtures only in a dedicated payroll-qa SQLite database';

    public function handle(): int
    {
        $db = (string) config('database.connections.sqlite.database');
        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite' || ! str_starts_with(basename($db), 'payroll-qa-') || ! getenv('PAYROLL_QA_PASSWORD')) {
            $this->error('Refusing fixtures: requires testing environment, payroll-qa-* SQLite and an ephemeral QA password.');

            return self::FAILURE;
        }
        if (User::exists() || Employee::exists()) {
            $this->error('Fixtures require a new empty QA database.');

            return self::FAILURE;
        }
        DB::transaction(function () {
            User::create(['name' => 'Isolated QA Admin', 'email' => 'qa@example.test', 'password' => Hash::make(getenv('PAYROLL_QA_PASSWORD')), 'role' => 'super_admin']);
            PayrollSetting::create(['policy' => ['effective_on' => '2026-01-01', 'company_name' => 'Payroll QA Demo', 'frequency' => 'monthly', 'proration' => 'calendar_days', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'hours_per_day' => 8, 'monthly_attendance' => 'not_required', 'paid_leave' => 'approved_only', 'ot_approval' => 'required', 'monthly_ot_divisor' => 30, 'contribution_timing' => 'monthly', 'compliance' => 'demo', 'labor_review' => 'ordinary_only', 'separate_approver' => false, 'reason' => 'Isolated academic QA fixtures only']]);
            foreach ([['M1', 'Full Monthly', '2026-09-01', 'monthly', 20000000], ['M2', 'Nov22 Hire', '2026-11-22', 'monthly', 20000000], ['D1', 'Daily Worker', '2026-09-01', 'daily', 80000]] as [$number,$name,$hire,$basis,$amount]) {
                $e = Employee::create(['employee_number' => $number, 'first_name' => $name, 'last_name' => 'QA', 'position' => 'Demo employee', 'hired_on' => $hire, 'is_active' => true, 'monthly_salary_cents' => $basis === 'monthly' ? $amount : 0]);
                $e->salaryRates()->create(['effective_on' => $hire, 'basis' => $basis, 'amount_cents' => $amount, 'reason' => 'Confirmed demo initial salary']);
                if ($basis === 'daily') {
                    foreach (CarbonPeriod::create('2026-10-01', '2026-11-30') as $day) {
                        AttendanceRecord::create(['employee_id' => $e->id, 'work_date' => $day->toDateString(), 'status' => 'present']);
                    }
                }
            }
            foreach ([['November QA', '2026-11-01', '2026-11-30'], ['October QA', '2026-10-01', '2026-10-31']] as [$label,$start,$end]) {
                PayrollPeriod::create(['label' => $label, 'starts_on' => $start, 'ends_on' => $end, 'status' => 'draft']);
            }
        });
        $this->info('Isolated demo fixtures created. No production connection used.');

        return self::SUCCESS;
    }
}
