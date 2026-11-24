<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

/** No date inference and no replacement of an existing authoritative history. */
final class LegacySalaryBackfill
{
    public function run(): array
    {
        $counts = ['created' => 0, 'needs_effective_date' => 0, 'existing_history' => 0];
        DB::table('employees')->where('monthly_salary_cents', '>', 0)->orderBy('id')->each(function ($employee) use (&$counts) {
            DB::transaction(function () use ($employee, &$counts) {
                $e = DB::table('employees')->where('id', $employee->id)->lockForUpdate()->first();
                if (DB::table('salary_rates')->where('employee_id', $e->id)->exists()) {
                    $counts['existing_history']++;

                    return;
                }
                if (! $e->hired_on) {
                    $counts['needs_effective_date']++;

                    return;
                }
                $id = DB::table('salary_rates')->insertGetId(['employee_id' => $e->id, 'effective_on' => $e->hired_on, 'basis' => 'monthly', 'amount_cents' => $e->monthly_salary_cents, 'reason' => 'Legacy backfill using recorded hire date; confirm historical accuracy before review', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => null, 'action' => 'rate_backfilled', 'subject_type' => 'SalaryRate', 'subject_id' => $id, 'before' => json_encode(['legacy_monthly_salary_cents' => $e->monthly_salary_cents, 'recorded_hired_on' => $e->hired_on]), 'after' => json_encode(['effective_on' => $e->hired_on, 'basis' => 'monthly', 'amount_cents' => $e->monthly_salary_cents, 'requires_review' => true]), 'created_at' => now()]);
                $counts['created']++;
            }, 3);
        });

        return $counts;
    }
}
