<?php

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SalaryRate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PayInputChanges
{
    public static function salaryRules(): array
    {
        return ['effective_on' => ['required', 'date'], 'basis' => ['required', Rule::in(['daily', 'monthly'])],
            'amount' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'], 'reason' => ['required', 'string', 'min:3', 'max:300']];
    }

    public static function validateSalary(Employee $employee, array $data): int
    {
        $cents = Money::toCents((string) $data['amount']);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Salary rate must be greater than zero.']);
        }
        if ($employee->salaryRates()->whereDate('effective_on', $data['effective_on'])->exists()) {
            throw ValidationException::withMessages(['effective_on' => 'A rate already exists on that effective date. Choose another date.']);
        }

        return $cents;
    }

    public static function addSalary(Employee $employee, array $data, int|string $actor): SalaryRate
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            Employee::whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $cents = self::validateSalary($employee, $data);

            return $employee->salaryRates()->create([
                'effective_on' => $data['effective_on'], 'basis' => $data['basis'], 'amount_cents' => $cents,
                'reason' => $data['reason'], 'created_by' => $actor,
            ]);
        });
    }

    public static function attendanceRules(AttendanceRecord $record): array
    {
        return ['approved_overtime_minutes' => 'required|integer|min:0|max:'.$record->overtime_minutes,
            'paid_leave' => 'required|boolean', 'leave_type' => 'nullable|string|max:60', 'reason' => 'required|string|min:5|max:500'];
    }

    public static function approveAttendance(AttendanceRecord $record, array $data, int|string $actor): void
    {
        DB::transaction(function () use ($data, $record, $actor) {
            $before = $record->getAttributes();
            $record->update(['approved_overtime_minutes' => $data['approved_overtime_minutes'], 'paid_leave' => $data['paid_leave'],
                'leave_type' => $data['leave_type'] ?? $record->leave_type, 'approved_by' => $actor, 'approved_at' => now()]);
            Audit::record('hours_leave_approved', $record, $before, $data, $actor);
        });
    }
}
