<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\AgentStepUp;
use App\Services\Payroll\PolicyChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AgentPolicyController
{
    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations, AgentStepUp $stepUp): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $settings = $operation === 'settings';
        $rules = $settings ? PolicyChanges::rules() : PolicyChanges::adjustmentRules();
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
        if ($settings) {
            $data = PolicyChanges::normalize($data);
            abort_unless($data['separate_approver'], 422);
        }

        return response()->json($mutations->run($actor, $settings ? 'settings' : 'payroll', 'agent-'.$operation, $data, $ticket,
            function (bool $lock) use ($settings, $data, $actor) {
                if ($settings && $lock) DB::table('payroll_locks')->where('id', 1)->lockForUpdate()->first();
                $revision = $settings ? PayrollSetting::latest('id')->first()?->getRawOriginal() : null;

                return [null, $revision, function () use ($settings, $data, $actor) {
                    $record = $settings ? PolicyChanges::save($data, $actor->getKey()) : PolicyChanges::addAdjustment($data, $actor->getKey());

                    return ['record' => $settings ? $this->policy($record) : $this->adjustment($record)];
                }];
            }));
    }

    public function show(Request $request, string $operation, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 422);
        $settings = $operation === 'settings';
        abort_unless(\App\Services\Payroll\Access::allows($request->user(), $settings ? 'settings' : 'payroll'), 403);

        return response()->json(['record' => $settings ? $this->policy(PayrollSetting::findOrFail($id))
            : $this->adjustment(PayrollAdjustment::findOrFail($id))]);
    }

    private function policy(PayrollSetting $row): array
    {
        return ['id' => (string) $row->id, 'effective_on' => $row->policy['effective_on'], 'frequency' => $row->policy['frequency']];
    }

    private function adjustment(PayrollAdjustment $row): array
    {
        return ['id' => (string) $row->id, 'label' => $row->label, 'type' => $row->type, 'amount_cents' => (int) $row->amount_cents];
    }
}
