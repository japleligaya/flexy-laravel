<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;

/**
 * Conservative attendance-derived draft: only recorded PRESENT days with an
 * effective DAILY rate count as base pay. No assumed working days, paid leave,
 * overtime, monthly salary proration, government deductions, or taxes.
 */
class AttendanceDraftCalculator
{
    public function preview(PayrollPeriod $period): array
    {
        $start = $period->starts_on->toDateString();
        $end = $period->ends_on->toDateString();
        $existing = $period->entries()->pluck('employee_id')->flip();
        $employees = Employee::query()->where('is_active', true)
            ->with([
                'salaryRates' => fn ($q) => $q->orderByDesc('effective_on'),
                'attendanceRecords' => fn ($q) => $q->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->orderBy('work_date'),
            ])->orderBy('last_name')->orderBy('first_name')->get();
        $rows = [];
        foreach ($employees as $employee) {
            $total = 0;
            $present = 0;
            $absent = 0;
            $leave = 0;
            $problem = null;
            foreach ($employee->attendanceRecords as $attendance) {
                if ($attendance->status === 'absent') {
                    $absent++;

                    continue;
                }
                if ($attendance->status === 'leave') {
                    $leave++;

                    continue;
                }
                if ($attendance->status !== 'present') {
                    continue;
                }
                $present++;
                $date = $attendance->work_date->toDateString();
                $rate = $employee->salaryRates->first(fn ($rate) => $rate->effective_on->toDateString() <= $date);
                if (! $rate) {
                    $problem = 'No effective rate for one or more present days';

                    continue;
                }
                if ($rate->basis !== 'daily') {
                    $problem = 'Monthly pay requires manual review; no automatic proration';

                    continue;
                }
                $total += (int) $rate->amount_cents;
            }
            if ($present === 0 && $employee->attendanceRecords->isEmpty()) {
                $problem = 'No recorded attendance in this period';
            } elseif ($present === 0) {
                $problem = 'No present days; review absences/leave manually';
            }
            if ($total > 99999999999) {
                $problem = 'Calculated amount exceeds per-entry limit';
            }
            $already = $existing->has($employee->getKey());
            $rows[] = [
                'employee' => $employee,
                'present_days' => $present,
                'absent_days' => $absent,
                'leave_days' => $leave,
                'base_pay_cents' => $total,
                'already_added' => $already,
                'eligible' => ! $already && ! $problem && $present > 0 && $total > 0,
                'reason' => $already ? 'Existing entry preserved (no overwrite)' : $problem,
            ];
        }

        return $rows;
    }
}
