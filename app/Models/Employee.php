<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Employee extends Model
{
    public static function rules(?self $employee = null): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:32', Rule::unique('employees', 'employee_number')->ignore($employee)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employee)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position' => ['required', 'string', 'max:255'],
            'monthly_salary' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'hired_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'payroll_classification' => ['sometimes', Rule::in(['unverified', 'standard_private_regular'])],
            'initial_effective_on' => ['nullable', 'date'],
            'initial_basis' => ['sometimes', Rule::in(['daily', 'monthly'])],
            'is_active' => ['required', Rule::in(['0', '1'])],
        ];
    }

    public function hasPayrollHistory(): bool
    {
        return $this->payrollEntries()->exists() || $this->attendanceRecords()->exists() || $this->salaryRates()->exists()
            || PayrollAdjustment::where('employee_id', $this->id)->exists();
    }

    protected $fillable = [
        'employee_number', 'first_name', 'last_name', 'email', 'department_id',
        'position', 'monthly_salary_cents', 'hired_on', 'is_active', 'ended_on', 'payroll_classification',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'hired_on' => 'date', 'ended_on' => 'date'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function salaryRates(): HasMany
    {
        return $this->hasMany(SalaryRate::class);
    }

    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function currentRate(): ?SalaryRate
    {
        return $this->salaryRates()->whereDate('effective_on', '<=', today())->latest('effective_on')->first();
    }

    public function salaryWarning(): ?string
    {
        $rate = $this->currentRate();
        if (! $rate) {
            return 'Needs review: no current authoritative rate. Confirm an effective date.';
        }
        if ($rate->basis !== 'monthly' || (int) $rate->amount_cents !== (int) $this->monthly_salary_cents) {
            return 'Legacy salary differs from authoritative salary history. Payroll uses salary_rates only.';
        }

        return null;
    }
}
