<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\AgentStepUp;
use App\Services\Payroll\Audit;
use App\Services\Payroll\ManualDraftEntry;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class AgentEntryWorkflowController
{
    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 422);

        return response()->json(['record' => $this->record(PayrollEntry::findOrFail($id))]);
    }

    public function preview(Request $request, string $id, PayrollWorkflow $workflow): JsonResponse
    {
        abort_unless($request->query() === [] && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 422);
        $entry = PayrollEntry::findOrFail($id);
        $candidate = $this->candidate($entry, $workflow);

        return response()->json(['record' => $this->record($entry) + $this->comparison($candidate)]);
    }

    public function change(Request $request, string $operation, string $stage, AgentMutation $mutations, AgentStepUp $stepUp,
        ManualDraftEntry $manual, PayrollEngine $engine, PayrollWorkflow $workflow): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $creating = in_array($operation, ['create', 'generate'], true);
        $rules = $creating ? ['period_id' => 'required|integer|min:1'] : ['id' => 'required|integer|min:1'];
        $rules += match ($operation) {
            'create' => $manual->rules(true),
            'update' => array_replace($manual->rules(false), array_fill_keys(ManualDraftEntry::AMOUNTS,
                ['sometimes', 'required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'])),
            'generate' => ['employee_id' => 'required|integer|min:1'],
            'delete' => ['reason' => 'required|string|min:5|max:500'],
        };
        if ($stage === 'execute') $rules += ['ticket' => ['required', 'string', AgentMutation::TICKET_RULE], 'password' => 'required|string|max:1024'];
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules) !== [], 422);
        $data = Validator::make($body, $rules)->validate();
        $actor = $request->user();
        $ticket = $data['ticket'] ?? null;
        if ($ticket !== null) {
            $stepUp->verify($actor, $data['password']);
            $request->json()->remove('password');
        }
        unset($data['ticket'], $data['password']);
        ksort($data);

        return response()->json($mutations->run($actor, 'payroll', 'entry-'.$operation, $data, $ticket,
            function (bool $lock) use ($operation, $creating, $data, $actor, $manual, $engine, $workflow) {
                $periodId = $creating ? $data['period_id'] : PayrollEntry::findOrFail($data['id'])->payroll_period_id;
                $period = $manual->draft($periodId, $lock);
                $entryQuery = $period->entries();
                $entry = $creating ? null : ($lock ? $entryQuery->lockForUpdate() : $entryQuery)->findOrFail($data['id']);
                $revision = [$period->getRawOriginal(), $entry?->getRawOriginal()];
                if ($operation === 'delete') {
                    abort_if($entry->reviewed_at, 409);

                    return [$this->record($entry), $revision, function () use ($entry, $data, $actor) {
                        Audit::record('draft_entry_removed', $entry, $entry->getAttributes(), ['reason' => $data['reason']], $actor->id);
                        $entry->delete();

                        return ['deleted' => (string) $entry->id];
                    }];
                }
                if ($operation === 'generate') {
                    $employee = $engine->employeeQuery($period)->findOrFail($data['employee_id']);
                    abort_if($period->entries()->where('employee_id', $employee->id)->exists(), 409);
                    $calculation = $engine->calculate($employee, $period);
                    $draft = $period->entries()->make(['employee_id' => $employee->id, 'source' => 'engine',
                        'calculation' => $calculation] + $workflow->money($calculation));
                } else {
                    $input = $data;
                    if ($entry) {
                        foreach (ManualDraftEntry::AMOUNTS as $field) {
                            $input[$field] ??= Money::input($entry->{$field.'_cents'});
                        }
                    }
                    $draft = $manual->prepare($period, $entry, $input);
                }
                $revision[] = $draft->calculation['fingerprint'];
                $target = array_replace($entry ? $this->record($entry) : [
                    'id' => (string) $period->id, 'employee_id' => (string) $draft->employee_id,
                ], $this->calculationSummary($draft));

                return [$target, $revision, function () use ($operation, $draft, $period, $manual, $workflow, $actor) {
                    $saved = $operation === 'generate'
                        ? $workflow->createCalculated($period, $draft->employee_id, $draft->calculation)
                        : $manual->persist($draft);
                    Audit::record('agent_entry_'.$operation, $saved, [], ['source' => $saved->source], $actor->id);

                    return ['record' => $this->record($saved)];
                }];
            }));
    }

    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations, AgentStepUp $stepUp, PayrollWorkflow $workflow): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $rules = ['id' => 'required|integer|min:1'];
        if ($operation === 'reopen') $rules['reason'] = 'required|string|min:5|max:500';
        if ($stage === 'execute') $rules += ['ticket' => ['required', 'string', AgentMutation::TICKET_RULE], 'password' => 'required|string|max:1024'];
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules) !== [], 422);
        $data = Validator::make($body, $rules)->validate();
        $actor = $request->user();
        $ticket = $data['ticket'] ?? null;
        if ($stage === 'execute') {
            $stepUp->verify($actor, $data['password']);
            $request->json()->remove('password');
        }
        unset($data['ticket'], $data['password']);

        return response()->json($mutations->run($actor, 'payroll', 'entry-'.$operation, $data, $ticket,
            function (bool $lock) use ($workflow, $operation, $actor, $data) {
                $entry = PayrollEntry::findOrFail($data['id']);
                if ($lock) {
                    PayrollPeriod::whereKey($entry->payroll_period_id)->lockForUpdate()->firstOrFail();
                    $entry = PayrollEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
                }
                abort_unless($entry->payrollPeriod->isDraft(), 409);
                $before = $this->record($entry);
                $revision = $entry->getRawOriginal();
                $hash = null;
                if ($operation === 'recalculate') {
                    $candidate = $this->candidate($entry, $workflow);
                    $hash = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR));
                    $revision['candidate_hash'] = $hash;
                    $before += $this->comparison($candidate);
                }
                if ($operation === 'review') {
                    abort_unless($entry->calculation && ! $entry->reviewed_at, 409);
                    $before += $this->calculationSummary($entry) + ['warnings' => count($entry->calculation['warnings'] ?? [])];
                }

                return [$before, $revision, function () use ($workflow, $operation, $entry, $actor, $data, $hash) {
                    $guard = Auth::guard('web');
                    $guard->setUser($actor);
                    try {
                        match ($operation) {
                            'recalculate' => $workflow->recalculate($entry, $hash),
                            'review' => $workflow->review($entry, true),
                            'reopen' => $workflow->reopen($entry, $data['reason']),
                        };

                        return ['record' => $this->record($entry->refresh())];
                    } finally {
                        $guard->forgetUser();
                    }
                }];
            }));
    }

    private function record(PayrollEntry $entry): array
    {
        return ['id' => (string) $entry->id, 'period_id' => (string) $entry->payroll_period_id,
            'employee_id' => (string) $entry->employee_id, 'source' => $entry->source,
            'reviewed' => $entry->reviewed_at !== null, 'net_cents' => $entry->net_pay_cents,
            'base_pay_cents' => (int) $entry->base_pay_cents, 'overtime_pay_cents' => (int) $entry->overtime_pay_cents,
            'allowances_cents' => (int) $entry->allowances_cents, 'deductions_cents' => (int) $entry->deductions_cents];
    }

    private function calculationSummary(PayrollEntry $entry): array
    {
        return array_intersect_key($entry->calculation['totals'], array_flip([
            'base_pay_cents', 'overtime_pay_cents', 'allowances_cents', 'deductions_cents', 'gross_cents',
        ])) + ['candidate_net_cents' => $entry->net_pay_cents, 'exceptions' => count($entry->calculation['exceptions'] ?? [])];
    }

    private function candidate(PayrollEntry $entry, PayrollWorkflow $workflow): array
    {
        abort_unless($entry->payrollPeriod->isDraft() && ! $entry->reviewed_at && in_array($entry->source, ['manual', 'engine'], true), 422);

        return $workflow->candidate($entry);
    }

    private function comparison(array $candidate): array
    {
        $amounts = $candidate['totals'];

        return ['candidate_net_cents' => $amounts['base_pay_cents'] + $amounts['overtime_pay_cents'] + $amounts['allowances_cents'] -
            $amounts['deductions_cents'], 'exceptions' => count($candidate['exceptions'] ?? [])];
    }
}
