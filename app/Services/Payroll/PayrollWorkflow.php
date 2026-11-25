<?php

namespace App\Services\Payroll;

use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PayrollWorkflow
{
    public function __construct(private PayrollEngine $engine) {}

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['period' => $message]);
    }

    public function locked(PayrollPeriod $period): PayrollPeriod
    {
        return PayrollPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
    }

    public function generate(PayrollPeriod $period, ?\App\Models\User $actor = null): int
    {
        if ($actor === null) {
            Access::check('payroll');
        } else {
            abort_unless(Access::allows($actor, 'payroll'), 403);
        }

        return DB::transaction(function () use ($period, $actor) {
            $period = $this->locked($period);
            if (! $period->isDraft()) {
                $this->fail('Only draft periods can be generated.');
            }
            $count = 0;
            foreach ($this->engine->employees($period) as $employee) {
                if ($period->entries()->where('employee_id', $employee->id)->exists()) {
                    continue;
                }
                $calculation = $this->engine->calculate($employee, $period);
                $this->createCalculated($period, $employee->id, $calculation);
                $count++;
            }
            Audit::record('batch_generated', $period, [], ['created' => $count], $actor?->getKey());

            return $count;
        }, 3);
    }

    public function money(array $c): array
    {
        return array_intersect_key($c['totals'], array_flip(['base_pay_cents', 'overtime_pay_cents', 'allowances_cents', 'deductions_cents']));
    }

    public function createCalculated(PayrollPeriod $period, int $employeeId, array $calculation): PayrollEntry
    {
        return $period->entries()->create(['employee_id' => $employeeId, 'source' => 'engine',
            'calculation' => $calculation] + $this->money($calculation));
    }

    public function candidate(PayrollEntry $entry): array
    {
        return $entry->source === 'manual' ? app(ManualCalculation::class)->calculate($entry) : $this->engine->calculate($entry->employee, $entry->payrollPeriod, $entry->statutory_overrides ?? []);
    }

    public function recalculate(PayrollEntry $entry, string $confirmedHash): void
    {
        Access::check('payroll');
        DB::transaction(function () use ($entry, $confirmedHash) {
            $period = $this->locked($entry->payrollPeriod);
            $entry = $entry->fresh();
            if (! $period->isDraft() || $entry->reviewed_at || ! in_array($entry->source, ['engine', 'manual'], true)) {
                $this->fail('Recalculation is limited to unreviewed drafts. Reopen a reviewed entry explicitly first.');
            }
            $c = $this->candidate($entry);
            if (! hash_equals(hash('sha256', json_encode($c)), $confirmedHash)) {
                $this->fail('Sources changed after preview. Preview the current amounts again.');
            }
            Audit::record('recalculated', $entry, $entry->calculation ?? [], $c);
            $entry->update(['calculation' => $c] + $this->money($c));
        }, 3);
    }

    public function review(PayrollEntry $entry, bool $acknowledged): void
    {
        Access::check('payroll');
        DB::transaction(function () use ($entry, $acknowledged) {
            $period = $this->locked($entry->payrollPeriod);
            $entry = $entry->fresh();
            if (! $period->isDraft()) {
                $this->fail('Only draft entries can be reviewed.');
            }
            if (! $acknowledged) {
                $this->fail('Acknowledge the calculation and warnings.');
            }
            if (! $entry->calculation || ! empty($entry->calculation['exceptions'])) {
                $this->fail('Resolve critical exceptions before review.');
            }
            if ($this->candidate($entry)['fingerprint'] !== $entry->calculation['fingerprint']) {
                $this->fail('Sources changed. Preview and confirm recalculation first.');
            }
            $entry->update(['reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        });
    }

    public function reviewReady(PayrollPeriod $period, int $confirmedCount, int $confirmedNet): int
    {
        Access::check('payroll');

        return DB::transaction(function () use ($period, $confirmedCount, $confirmedNet) {
            $period = $this->locked($period);
            if (! $period->isDraft()) {
                $this->fail('Only Draft payroll can acknowledge ready employees.');
            }
            $ready = $period->entries()->with('employee')->get()->filter(function ($entry) {
                return ! $entry->reviewed_at && $entry->calculation && empty($entry->calculation['exceptions']) && $this->candidate($entry)['fingerprint'] === ($entry->calculation['fingerprint'] ?? null);
            });
            if ($ready->count() !== $confirmedCount || $ready->sum(fn ($e) => $e->net_pay_cents) !== $confirmedNet) {
                $this->fail('Ready employees or amounts changed. Review the current readiness summary again.');
            }
            foreach ($ready as $entry) {
                $entry->update(['reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
            }
            Audit::record('ready_batch_acknowledged', $period, [], ['employee_ids' => $ready->pluck('employee_id')->all(), 'net_cents' => $confirmedNet]);

            return $ready->count();
        }, 3);
    }

    public function reopen(PayrollEntry $entry, string $reason): void
    {
        Access::check('payroll');
        DB::transaction(function () use ($entry, $reason) {
            $period = $this->locked($entry->payrollPeriod);
            if (! $period->isDraft()) {
                $this->fail('Return the batch to Draft before reopening an entry.');
            }
            $entry->update(['reviewed_by' => null, 'reviewed_at' => null]);
            Audit::record('review_reopened', $entry, [], ['reason' => $reason]);
        });
    }

    private function complete(PayrollPeriod $period): void
    {
        $expected = $this->engine->employees($period)->pluck('id')->sort()->values()->all();
        $entries = $period->entries()->get();
        $actual = $entries->pluck('employee_id')->sort()->values()->all();
        if (! $expected || $expected !== $actual) {
            $this->fail('Batch is incomplete: every eligible employee must have exactly one entry. Generate and resolve all missing/extra employees.');
        }
        foreach ($entries as $e) {
            if (! $e->reviewed_at || ! $e->calculation || ! empty($e->calculation['exceptions'])) {
                $this->fail('Every entry needs review and zero unresolved critical exceptions.');
            }
            if ($this->candidate($e)['fingerprint'] !== $e->calculation['fingerprint']) {
                $this->fail('Source data changed after review. Return to Draft, reopen and recalculate.');
            }
            if ($this->money($e->calculation) !== array_map('intval', array_intersect_key($e->getAttributes(), array_flip(['base_pay_cents', 'overtime_pay_cents', 'allowances_cents', 'deductions_cents'])))) {
                foreach ($this->money($e->calculation) as $key => $value) {
                    if ((int) $e->$key !== (int) $value) {
                        $this->fail('Stored totals differ from calculation lines. Recalculate before approval.');
                    }
                }
            }
            if ($e->net_pay_cents < 0) {
                $this->fail('Negative net is not allowed.');
            }
        }
    }

    public function transition(PayrollPeriod $period, string $action, string $reason = '', bool $ack = false, ?int $confirmedNet = null): void
    {
        Access::check(in_array($action, ['approve', 'reject', 'finalize'], true) ? 'approve' : 'payroll');
        DB::transaction(function () use ($period, $action, $reason, $ack, $confirmedNet) {
            $period = $this->locked($period);
            $w = $period->workflow ?? [];
            $policy = PayrollSetting::forDate($period->starts_on->toDateString());
            $from = $period->status;
            $next = match ($action) {
                'submit' => 'review','approve' => 'approved','reject' => 'draft','finalize' => 'finalized',default => null
            };
            $allowed = match ($action) {
                'submit' => ['draft'],'approve' => ['review'],'reject' => ['review', 'approved'],'finalize' => ['approved'],default => []
            };
            if (! in_array($from, $allowed, true)) {
                $this->fail('Invalid workflow transition: '.$from.' → '.$action);
            }
            if ($action !== 'reject') {
                $this->complete($period);
            }
            if (in_array($action, ['submit', 'finalize'], true)) {
                if (! $ack) {
                    $this->fail('Review acknowledgement is required.');
                }
                $net = $period->entries()->get()->sum(fn ($e) => $e->net_pay_cents);
                if ($confirmedNet !== $net) {
                    $this->fail('Totals changed or were not acknowledged. Review the batch net total.');
                }
            }
            if ($action === 'approve' && ($policy['separate_approver'] ?? true)) {
                if (($w['submit']['actor_id'] ?? null) === auth()->id() || $period->entries()->where('reviewed_by', auth()->id())->exists()) {
                    $this->fail('A separate actor must approve this payroll.');
                }
            }
            $w[$action] = ['actor_id' => auth()->id(), 'at' => now()->toIso8601String(), 'reason' => $reason, 'from' => $from];
            if ($action === 'approve') {
                $w[$action]['snapshot'] = ['policy' => $policy, 'entries' => $period->entries()->get()->map(fn ($e) => ['entry_id' => $e->id, 'employee_id' => $e->employee_id, 'reviewed_by' => $e->reviewed_by, 'calculation' => $e->calculation, 'totals' => $this->money($e->calculation)])->all()];
            }
            $attributes = ['status' => $next, 'workflow' => $w];
            if ($action === 'reject') {
                $period->update(['status' => 'draft']);
                foreach ($period->entries()->get() as $entry) {
                    $entry->update(['reviewed_by' => null, 'reviewed_at' => null]);
                }
            }
            if ($action === 'finalize') {
                foreach ($period->entries()->with('employee')->get() as $e) {
                    $snapshot = ['employee' => ['id' => $e->employee_id, 'number' => $e->employee->employee_number, 'name' => $e->employee->full_name, 'position' => $e->employee->position],
                        'company' => $policy['company_name'] ?? config('app.name'), 'period' => ['id' => $period->id, 'label' => $period->label, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString()],
                        'calculation' => $e->calculation, 'totals' => $this->money($e->calculation) + ['gross_cents' => $e->gross_pay_cents, 'net_cents' => $e->net_pay_cents],
                        'finalized_at' => now()->toIso8601String(), 'finalized_by' => auth()->id(), 'reviewed_by' => $e->reviewed_by];
                    $e->update(['snapshot' => $snapshot]);
                }
                $attributes['finalized_at'] = now();
                $attributes['snapshot'] = ['employee_ids' => $period->entries()->pluck('employee_id')->all(), 'policy' => $policy, 'net_cents' => $period->entries()->get()->sum(fn ($e) => $e->net_pay_cents), 'at' => now()->toIso8601String()];
            }
            $period->update($attributes);
            Audit::record($action, $period, ['status' => $from], $attributes);
        }, 3);
    }

    public function pay(PayrollPeriod $period, array $data): void
    {
        Access::check('pay');
        DB::transaction(function () use ($period, $data) {
            $period = $this->locked($period);
            if ($period->status !== 'finalized') {
                $this->fail('Only finalized payroll can record payments.');
            }
            if (PayrollCorrection::where('payroll_period_id', $period->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                $this->fail('Payment is blocked by a pending or approved reversal. Reconcile the correction first.');
            }
            if (($period->snapshot['policy']['compliance'] ?? null) === 'demo') {
                $this->fail('Demo payroll cannot record actual payment.');
            }
            $total = $period->entries()->get()->sum(fn ($e) => (int) ($e->snapshot['totals']['net_cents'] ?? $e->net_pay_cents));
            if (! $period->snapshot || $period->entries()->whereNull('snapshot')->exists()) {
                $this->fail('Legacy finalization needs verified historical reconciliation before recording payment.');
            }
            $already = (int) PayrollPayment::where('payroll_period_id', $period->id)->sum('amount_cents');
            if ($data['amount_cents'] < 1 || $data['amount_cents'] > $total - $already) {
                $this->fail('Payment exceeds unreconciled net or is zero.');
            }
            PayrollPayment::create($data + ['payroll_period_id' => $period->id, 'confirmed_by' => auth()->id()]);
            if ($already + $data['amount_cents'] === $total) {
                $w = $period->workflow ?? [];
                $w['paid'] = ['actor_id' => auth()->id(), 'at' => now()->toIso8601String()];
                $period->update(['status' => 'paid', 'workflow' => $w]);
            }
        }, 3);
    }
}
