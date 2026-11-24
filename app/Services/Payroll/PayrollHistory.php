<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;

final class PayrollHistory
{
    public function summarize(Employee $employee, PayrollPeriod $period): array
    {
        $entries = PayrollEntry::where('employee_id', $employee->id)->whereHas('payrollPeriod', fn ($q) => $q
            ->whereIn('status', ['finalized', 'paid'])->whereDate('starts_on', '>=', $period->starts_on->copy()->startOfYear())
            ->whereDate('ends_on', '<', $period->starts_on)
            ->whereNotIn('id', PayrollCorrection::where('status', 'approved')->select('payroll_period_id')))->with('payrollPeriod')->orderBy('id')->get();
        $summary = ['basis' => 'Finalized earning periods; these totals do not imply Paid. Reconcile cash payment dates separately.', 'mtd' => [], 'ytd' => []];
        foreach (['mtd', 'ytd'] as $scope) {
            $rows = $scope === 'mtd' ? $entries->filter(fn ($e) => $e->payrollPeriod->starts_on->format('Y-m') === $period->starts_on->format('Y-m')) : $entries;
            $real = $rows->filter(fn ($e) => ($e->snapshot['calculation']['policy']['compliance'] ?? null) !== 'demo');
            $unknown = $real->filter(fn ($e) => ! $e->snapshot)->count();
            $taxUnknown = $real->filter(fn ($e) => ! isset($e->snapshot['calculation']['statutory']['bir']['taxable_cents']))->count();
            $summary[$scope] = ['entry_ids' => $real->pluck('id')->all(), 'excluded_demo_count' => $rows->count() - $real->count(), 'unverified_legacy_count' => $unknown,
                'gross_cents' => $unknown ? null : $real->sum(fn ($e) => (int) $e->snapshot['totals']['gross_cents']),
                'taxable_cents' => $taxUnknown ? null : $real->sum(fn ($e) => (int) $e->snapshot['calculation']['statutory']['bir']['taxable_cents']),
                'contributions' => []];
            foreach (['sss', 'philhealth', 'pagibig', 'bir'] as $key) {
                foreach (['employee', 'employer'] as $share) {
                    $missing = $real->contains(fn ($e) => ! isset($e->snapshot['calculation']['statutory'][$key][$share]));
                    $summary[$scope]['contributions'][$key][$share] = $missing ? null : $real->sum(fn ($e) => (int) $e->snapshot['calculation']['statutory'][$key][$share]);
                }
            }
        }

        return $summary;
    }
}
