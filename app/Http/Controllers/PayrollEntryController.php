<?php

namespace App\Http\Controllers;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\Audit;
use App\Services\Payroll\ManualDraftEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollEntryController extends Controller
{
    public function store(Request $request, PayrollPeriod $payrollPeriod, ManualDraftEntry $manual): RedirectResponse
    {
        $manual->save($payrollPeriod, null, $request->all());

        return back()->with('success', 'Payroll entry added. Amounts are manually entered, not statutory calculations.');
    }

    public function update(Request $request, PayrollPeriod $payrollPeriod, PayrollEntry $payrollEntry, ManualDraftEntry $manual): RedirectResponse
    {
        $this->ensureBelongs($payrollPeriod, $payrollEntry);
        $manual->save($payrollPeriod, $payrollEntry, $request->all());

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
