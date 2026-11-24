<?php

namespace App\Http\Controllers;

use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Services\Payroll\Access;
use App\Services\Payroll\Audit;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollEngineController extends Controller
{
    public function reviewReady(Request $r, PayrollPeriod $payrollPeriod, PayrollWorkflow $w)
    {
        $d = $r->validate(['acknowledged' => 'accepted', 'confirmed_count' => 'required|integer|min:0', 'confirmed_net' => 'required|integer|min:0']);
        $count = $w->reviewReady($payrollPeriod, (int) $d['confirmed_count'], (int) $d['confirmed_net']);

        return back()->with('success', $count.' ready employees acknowledged together. Resolve only the remaining exceptions.');
    }

    public function generate(PayrollPeriod $payrollPeriod, PayrollWorkflow $w)
    {
        $n = $w->generate($payrollPeriod);

        return back()->with('success', $n.' drafts generated. Existing entries preserved. Resolve exceptions, review, then submit.');
    }

    public function preview(PayrollEntry $payrollEntry, PayrollWorkflow $w)
    {
        abort_unless($payrollEntry->payrollPeriod->isDraft() && in_array($payrollEntry->source, ['engine', 'manual'], true) && ! $payrollEntry->reviewed_at, 422);
        $candidate = $w->candidate($payrollEntry);

        return view('payroll-periods.recalculate', ['entry' => $payrollEntry, 'candidate' => $candidate, 'hash' => hash('sha256', json_encode($candidate))]);
    }

    public function recalculate(Request $r, PayrollEntry $payrollEntry, PayrollWorkflow $w)
    {
        $d = $r->validate(['preview_hash' => 'required|string|size:64', 'confirmed' => 'accepted']);
        $w->recalculate($payrollEntry, $d['preview_hash']);

        return redirect()->route('payroll-periods.show', $payrollEntry->payroll_period_id)->with('success', 'Recalculated with audited before/after values.');
    }

    public function review(Request $r, PayrollEntry $payrollEntry, PayrollWorkflow $w)
    {
        $r->validate(['acknowledged' => 'accepted']);
        $w->review($payrollEntry, true);

        return back()->with('success', 'Entry reviewed.');
    }

    public function reopen(Request $r, PayrollEntry $payrollEntry, PayrollWorkflow $w)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500']);
        $w->reopen($payrollEntry, $d['reason']);

        return back()->with('success', 'Entry reopened.');
    }

    public function transition(Request $r, PayrollPeriod $payrollPeriod, string $action, PayrollWorkflow $w)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'reject'], true), 404);
        $d = $r->validate(['reason' => ($action === 'reject' ? 'required' : 'nullable').'|string|min:5|max:500', 'acknowledged' => 'sometimes|accepted', 'confirmed_net' => 'nullable|integer|min:0']);
        $w->transition($payrollPeriod, $action, $d['reason'] ?? '', $r->boolean('acknowledged'), isset($d['confirmed_net']) ? (int) $d['confirmed_net'] : null);

        return back()->with('success', 'Payroll '.$action.' recorded.');
    }

    public function override(Request $r, PayrollEntry $payrollEntry, PayrollWorkflow $w)
    {
        Access::check('approve');
        $d = $r->validate(['component' => 'required|in:sss,philhealth,pagibig,bir', 'employee' => 'required|regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'employer' => 'required|regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'reason' => 'required|string|min:10|max:500', 'source_url' => 'required|url|max:500']);
        DB::transaction(function () use ($payrollEntry, $w, $d) {
            $p = $w->locked($payrollEntry->payrollPeriod);
            $e = $payrollEntry->fresh();
            if (! $p->isDraft() || $e->reviewed_at || (PayrollSetting::forDate($p->starts_on->toDateString())['compliance'] ?? 'strict') !== 'manual') {
                $w->fail('Manual statutory amounts require manual-compliance mode and an unreviewed draft.');
            }
            $o = $e->statutory_overrides ?? [];
            $o[$d['component']] = ['employee' => Money::toCents($d['employee']), 'employer' => Money::toCents($d['employer']), 'reason' => $d['reason'], 'source_url' => $d['source_url'], 'actor_id' => auth()->id(), 'at' => now()->toIso8601String()];
            $e->update(['statutory_overrides' => $o]);
            Audit::record('statutory_manual', $e, [], $o);
        });

        return back()->with('success', 'Authorized manual amount recorded. Preview and confirm recalculation.');
    }

    public function payment(Request $r, PayrollPeriod $payrollPeriod, PayrollWorkflow $w)
    {
        $d = $r->validate(['paid_on' => 'required|date|before_or_equal:today', 'method' => 'required|in:cash,bank,check,other', 'reference' => 'required|string|max:120|unique:payroll_payments,reference', 'amount' => 'required|regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'reason' => 'required|string|min:5|max:500', 'confirmed' => 'accepted']);
        $d['amount_cents'] = Money::toCents($d['amount']);
        unset($d['amount'],$d['confirmed']);
        $w->pay($payrollPeriod, $d);

        return back()->with('success', 'Actual payment recorded and reconciled separately.');
    }

    public function correction(Request $r, PayrollPeriod $payrollPeriod, PayrollWorkflow $w)
    {
        $d = $r->validate(['reason' => 'required|string|min:10|max:500', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($payrollPeriod, $w, $d) {
            $p = $w->locked($payrollPeriod);
            if (! in_array($p->status, ['finalized', 'paid'], true)) {
                $w->fail('Corrections reference locked payroll only.');
            }
            if (PayrollCorrection::where('payroll_period_id', $p->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                $w->fail('A pending or approved reversal already exists.');
            }
            $entries = $p->entries()->get();
            if ($entries->contains(fn ($e) => ! $e->snapshot)) {
                $w->fail('Legacy payroll without snapshots requires verified historical reconciliation before a reversal.');
            }
            $snapshot = $entries->map(fn ($e) => ['entry_id' => $e->id, 'original' => $e->snapshot, 'gross_delta_cents' => -$e->snapshot['totals']['gross_cents'], 'deductions_delta_cents' => -$e->snapshot['totals']['deductions_cents'], 'net_delta_cents' => -$e->snapshot['totals']['net_cents']])->all();
            $c = PayrollCorrection::create(['payroll_period_id' => $p->id, 'net_delta_cents' => array_sum(array_column($snapshot, 'net_delta_cents')), 'snapshot' => $snapshot, 'reason' => $d['reason'], 'requested_by' => auth()->id()]);
            Audit::record('reversal_requested', $p, [], ['correction_id' => $c->id, 'reason' => $d['reason'], 'net_delta_cents' => $c->net_delta_cents]);
        });

        return back()->with('success', 'Full reversal requested with exact negative snapshot amounts. Approve separately; no funds are transferred or recovered automatically.');
    }

    public function replacement(PayrollCorrection $correction, PayrollWorkflow $w)
    {
        $replacement = DB::transaction(function () use ($correction, $w) {
            $p = $w->locked(PayrollPeriod::findOrFail($correction->payroll_period_id));
            if ($correction->fresh()->status !== 'approved') {
                $w->fail('Approve the full reversal before preparing replacement payroll.');
            }
            DB::table('payroll_locks')->where('id', 1)->lockForUpdate()->first();
            if (PayrollPeriod::where('correction_of', $p->id)->where('status', '!=', 'cancelled')->exists()) {
                $w->fail('An active replacement already exists.');
            }
            $replacement = PayrollPeriod::create(['label' => mb_substr('Replacement: '.$p->label, 0, 255), 'starts_on' => $p->starts_on, 'ends_on' => $p->ends_on, 'status' => 'draft', 'correction_of' => $p->id]);
            Audit::record('replacement_created', $replacement, [], ['original_period_id' => $p->id, 'reversal_id' => $correction->id]);

            return $replacement;
        }, 3);

        return redirect()->route('payroll-periods.show', $replacement)->with('success', 'Replacement draft created with original dates and an explicit reversal link. Generate, review and approve it normally.');
    }

    public function correctionDecision(Request $r, PayrollCorrection $correction, PayrollWorkflow $w)
    {
        $d = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|min:10|max:500', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($correction, $w, $d) {
            $p = $w->locked(PayrollPeriod::findOrFail($correction->payroll_period_id));
            $c = PayrollCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'pending') {
                $w->fail('This reversal already has a locked decision.');
            }
            if ((($p->snapshot['policy'] ?? [])['separate_approver'] ?? true) && (int) $c->requested_by === (int) auth()->id()) {
                $w->fail('A separate actor must decide this reversal.');
            }
            $c->update(['status' => $d['decision'], 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_reason' => $d['reason']]);
            Audit::record('reversal_'.$d['decision'], $p, [], ['correction_id' => $c->id, 'net_delta_cents' => $c->net_delta_cents, 'reason' => $d['reason']]);
        });

        return back()->with('success', 'Reversal decision retained. Original payroll and payment records remain locked. Any recovery / replacement disbursement needs separate reconciliation.');
    }
}
