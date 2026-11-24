<?php

namespace App\Http\Controllers;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\Audit;
use App\Services\Payroll\ManualCalculation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollEntryController extends Controller
{
    public function store(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        $data = $this->validated($request, $payrollPeriod);
        DB::transaction(function () use ($payrollPeriod, $data) {
            $period = $this->lockDraft($payrollPeriod);
            if ($period->entries()->where('employee_id', $data['employee_id'])->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Employee already has an entry in this period.']);
            }
            $entry = $period->entries()->create($data + ['source' => 'manual']);
            $entry->update(['calculation' => app(ManualCalculation::class)->calculate($entry)]);
        });

        return back()->with('success', 'Payroll entry added. Amounts are manually entered, not statutory calculations.');
    }

    public function update(Request $request, PayrollPeriod $payrollPeriod, PayrollEntry $payrollEntry): RedirectResponse
    {
        $this->ensureBelongs($payrollPeriod, $payrollEntry);
        $data = $this->validated($request, $payrollPeriod, false);
        DB::transaction(function () use ($payrollPeriod, $payrollEntry, $data) {
            $this->lockDraft($payrollPeriod);
            if ($payrollEntry->fresh()->reviewed_at) {
                throw ValidationException::withMessages(['period' => 'Reopen the review explicitly before changing this entry.']);
            }
            $payrollEntry->update($data + ['source' => 'manual', 'calculation' => null, 'reviewed_at' => null, 'reviewed_by' => null]);
            $payrollEntry->update(['calculation' => app(ManualCalculation::class)->calculate($payrollEntry->fresh())]);
        });

        return back()->with('success', 'Payroll entry updated.');
    }

    public function destroy(Request $request, PayrollPeriod $payrollPeriod, PayrollEntry $payrollEntry): RedirectResponse
    {
        $this->ensureBelongs($payrollPeriod, $payrollEntry);
        DB::transaction(function () use ($request, $payrollPeriod, $payrollEntry) {
            $this->lockDraft($payrollPeriod);
            if ($payrollEntry->fresh()->reviewed_at) {
                throw ValidationException::withMessages(['period' => 'Reopen the review explicitly before removing this entry.']);
            }
            $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
            Audit::record('draft_entry_removed', $payrollEntry, $payrollEntry->getAttributes(), ['reason' => $data['reason']]);
            $payrollEntry->delete();
        });

        return back()->with('success', 'Draft payroll entry removed.');
    }

    private function validated(Request $request, PayrollPeriod $period, bool $creating = true): array
    {
        $rules = [
            'base_pay' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'overtime_pay' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'allowances' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'deductions' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'notes' => ['required', 'string', 'min:10', 'max:500'],
        ];
        if ($creating) {
            $rules['employee_id'] = ['required', 'integer', Rule::exists('employees', 'id')->where('is_active', true)];
        }
        $data = $request->validate($rules);
        foreach (['base_pay', 'overtime_pay', 'allowances', 'deductions'] as $field) {
            $data[$field.'_cents'] = Money::toCents((string) $data[$field]);
            unset($data[$field]);
        }
        $gross = $data['base_pay_cents'] + $data['overtime_pay_cents'] + $data['allowances_cents'];
        if ($data['deductions_cents'] > $gross) {
            throw ValidationException::withMessages(['deductions' => 'Deductions cannot exceed gross pay.']);
        }

        return $data;
    }

    private function lockDraft(PayrollPeriod $payrollPeriod): PayrollPeriod
    {
        $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
        if (! $period->isDraft()) {
            throw ValidationException::withMessages(['period' => 'Only draft payroll entries can be changed.']);
        }

        return $period;
    }

    private function ensureBelongs(PayrollPeriod $period, PayrollEntry $entry): void
    {
        abort_unless($entry->payroll_period_id === $period->id, 404);
    }
}
