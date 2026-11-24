<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollCorrection;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollWorkflow;
use App\Services\Payroll\CutoffDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PayrollPeriodController extends Controller
{
    public function index(): View
    {
        return view('payroll-periods.index', [
            'periods' => PayrollPeriod::withCount('entries')->latest('starts_on')->paginate(10),
            'hasPolicy' => !empty(PayrollSetting::current()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $period = app(CutoffDraft::class)->create($request->validate(CutoffDraft::rules()));

        return redirect()->route('payroll-periods.show', $period)->with('success', 'Draft payroll period created.');
    }

    public function show(PayrollPeriod $payrollPeriod): View
    {
        $entries = $payrollPeriod->entries()->with('employee')->orderBy('id')->get();
        $staleIds = collect();
        if (in_array($payrollPeriod->status, ['draft', 'review', 'approved'], true)) {
            foreach ($entries as $entry) {
                if ($entry->calculation && in_array($entry->source, ['engine', 'manual'], true) && app(PayrollWorkflow::class)->candidate($entry)['fingerprint'] !== ($entry->calculation['fingerprint'] ?? null)) {
                    $staleIds->push($entry->id);
                }
            }
        }

        return view('payroll-periods.show', [
            'period' => $payrollPeriod,
            'entries' => $entries,
            'staleIds' => $staleIds,
            'employees' => Employee::where('is_active', true)
                ->whereNotIn('id', $entries->pluck('employee_id'))
                ->orderBy('last_name')->get(),
            'grossCents' => $entries->sum(fn ($entry) => $entry->gross_pay_cents),
            'netCents' => $entries->sum(fn ($entry) => $entry->net_pay_cents),
            'eligible' => app(PayrollEngine::class)->employees($payrollPeriod),
            'excluded' => Employee::whereDate('hired_on', '>', $payrollPeriod->ends_on)->get(),
            'policy' => $payrollPeriod->snapshot['policy'] ?? PayrollSetting::forDate($payrollPeriod->starts_on->toDateString()),
            'payments' => PayrollPayment::where('payroll_period_id', $payrollPeriod->id)->get(),
            'audit' => DB::table('audit_events')->where('subject_type', 'PayrollPeriod')->where('subject_id', $payrollPeriod->id)->latest('id')->limit(30)->get(),
            'corrections' => PayrollCorrection::where('payroll_period_id', $payrollPeriod->id)->get(),
        ]);
    }

    public function cancel(Request $request, PayrollPeriod $payrollPeriod): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_cancel' => ['accepted'],
        ]);
        DB::transaction(function () use ($payrollPeriod, $data, $request) {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            app(CutoffDraft::class)->cancel($period, $data['reason'], $request->user()->getAuthIdentifier());
        });

        return redirect()->route('payroll-periods.index')
            ->with('success', 'Draft cancelled and retained in history. The same date range can be used in a new draft.');
    }

    public function finalize(Request $request, PayrollPeriod $payrollPeriod, PayrollWorkflow $workflow): RedirectResponse
    {
        $workflow->transition($payrollPeriod, 'finalize', '', $request->boolean('acknowledged'), $request->filled('confirmed_net') ? (int) $request->input('confirmed_net') : null);

        return back()->with('success', 'Period finalized and locked. This does NOT mark payroll as paid.');
    }
}
