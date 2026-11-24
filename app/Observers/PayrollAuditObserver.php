<?php

namespace App\Observers;

use App\Models\PayrollAdjustment;
use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\SalaryRate;
use App\Services\Payroll\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PayrollAuditObserver
{
    public function updating(Model $m): void
    {
        if ($m instanceof PayrollEntry && $m->getRawOriginal('reviewed_at') && $m->payrollPeriod()->value('status') === 'draft' && array_diff(array_keys($m->getDirty()), ['reviewed_at', 'reviewed_by', 'updated_at'])) {
            throw ValidationException::withMessages(['period' => 'Reopen review explicitly before changing money or source data.']);
        }
        if ($m instanceof PayrollCorrection && ($m->getRawOriginal('status') !== 'pending' || array_diff(array_keys($m->getDirty()), ['status', 'decided_by', 'decided_at', 'decision_reason', 'updated_at']))) {
            throw ValidationException::withMessages(['period' => 'Correction amounts and decisions are immutable.']);
        }
        if ($m instanceof SalaryRate || $m instanceof PayrollSetting || $m instanceof PayrollPayment) {
            throw ValidationException::withMessages(['history' => 'Historical records are immutable. Add a new effective version or controlled correction.']);
        }
        if ($m instanceof PayrollEntry && in_array($m->payrollPeriod()->value('status'), ['review', 'approved', 'finalized', 'paid', 'cancelled'], true)) {
            $snapshotOnly = $m->payrollPeriod()->value('status') === 'approved' && ! $m->getRawOriginal('snapshot') && ! array_diff(array_keys($m->getDirty()), ['snapshot', 'updated_at']);
            if (! $snapshotOnly) {
                throw ValidationException::withMessages(['period' => 'Payroll entries are locked outside Draft.']);
            }
        }
        if ($m instanceof PayrollPeriod && in_array($m->getRawOriginal('status'), ['finalized', 'paid'], true)) {
            $allowed = $m->getRawOriginal('status') === 'finalized' && $m->status === 'paid' && ! array_diff(array_keys($m->getDirty()), ['status', 'workflow', 'updated_at']);
            if (! $allowed) {
                throw ValidationException::withMessages(['period' => 'Finalized payroll history is immutable.']);
            }
        }
    }

    public function deleting(Model $m): void
    {
        if ($m instanceof PayrollCorrection || $m instanceof SalaryRate || $m instanceof PayrollSetting || $m instanceof PayrollPayment || $m instanceof PayrollAdjustment || $m instanceof PayrollPeriod || ($m instanceof PayrollEntry && ! $m->payrollPeriod->isDraft())) {
            throw ValidationException::withMessages(['period' => 'Historical payroll records cannot be deleted.']);
        }
    }

    public function created(Model $m): void
    {
        Audit::record('created', $m, [], $m->getAttributes());
    }

    public function updated(Model $m): void
    {
        Audit::record('updated', $m, array_intersect_key($m->getRawOriginal(), $m->getChanges()), $m->getChanges());
    }

    public function deleted(Model $m): void
    {
        Audit::record('deleted', $m, $m->getAttributes());
    }
}
