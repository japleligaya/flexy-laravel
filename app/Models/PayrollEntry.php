<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_id', 'base_pay_cents', 'overtime_pay_cents',
        'allowances_cents', 'deductions_cents', 'notes', 'source', 'calculation', 'snapshot', 'statutory_overrides', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['calculation' => 'array', 'snapshot' => 'array', 'statutory_overrides' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getGrossPayCentsAttribute(): int
    {
        return $this->base_pay_cents + $this->overtime_pay_cents + $this->allowances_cents;
    }

    public function getNetPayCentsAttribute(): int
    {
        return $this->gross_pay_cents - $this->deductions_cents;
    }
}
