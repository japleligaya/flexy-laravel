<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Services\Payroll\Access;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\AgentStepUp;
use App\Services\Payroll\PayrollWorkflow;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class AgentWorkflowController
{
    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations, AgentStepUp $stepUp, PayrollWorkflow $workflow): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $execute = $stage === 'execute';
        $rules = ['id' => 'required|integer|min:1'] + match ($operation) {
            'submit', 'finalize' => ['confirmed_net' => 'required|integer|min:0'],
            'approve' => ['reason' => 'sometimes|required|string|min:5|max:500'],
            'reject' => ['reason' => 'required|string|min:5|max:500'],
            'payment' => ['paid_on' => 'required|date|before_or_equal:today', 'method' => 'required|in:cash,bank,check,other',
                'reference' => 'required|string|max:120|unique:payroll_payments,reference', 'amount' => 'required|regex:/^\d{1,9}(?:\.\d{1,2})?$/D',
                'reason' => 'required|string|min:5|max:500'],
        };
        if ($execute) {
            $rules += ['ticket' => ['required', 'string', AgentMutation::TICKET_RULE], 'password' => ['required', 'string', 'max:1024']];
        }
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules) !== [], 422);
        $data = Validator::make($body, $rules)->validate();
        $actor = $request->user();
        $ticket = $data['ticket'] ?? null;
        if ($execute) {
            $stepUp->verify($actor, $data['password']);
            $request->json()->remove('password');
        }
        unset($data['password'], $data['ticket']);
        ksort($data);
        $permission = match ($operation) { 'submit' => 'payroll', 'payment' => 'pay', default => 'approve' };
        $allowed = match ($operation) {
            'submit' => ['draft'], 'approve' => ['review'], 'reject' => ['review', 'approved'],
            'finalize' => ['approved'], 'payment' => ['finalized'],
        };

        return response()->json($mutations->run($actor, $permission, 'workflow-'.$operation, $data, $ticket,
            function (bool $lock) use ($operation, $data, $allowed, $actor, $workflow) {
                $query = PayrollPeriod::query();
                $period = ($lock ? $query->lockForUpdate() : $query)->findOrFail($data['id']);
                abort_unless(in_array($period->status, $allowed, true), 409);
                // Bind the preview to the current batch without exposing the calculation or snapshot.
                abort_if($period->entries()->offset(100)->limit(1)->exists(), 422);
                $revision = $period->getRawOriginal();
                $revision['entries'] = $period->entries()->orderBy('id')->get()->map(fn ($entry) => $entry->getRawOriginal())->all();
                if (isset($data['confirmed_net'])) {
                    abort_unless((int) $data['confirmed_net'] === $period->entries()->get()->sum(fn ($entry) => $entry->net_pay_cents), 409);
                }
                $before = $this->record($period);

                return [$before, $revision, function () use ($operation, $data, $period, $actor, $workflow) {
                    $guard = Auth::guard('web');
                    // Supply the verified bearer identity to the existing web-domain checks without a session login.
                    $guard->setUser($actor);
                    try {
                        if ($operation === 'payment') {
                            $workflow->pay($period, [
                                'paid_on' => $data['paid_on'], 'method' => $data['method'], 'reference' => $data['reference'],
                                'amount_cents' => Money::toCents($data['amount']), 'reason' => $data['reason'],
                            ]);
                            return ['record' => $this->record($period->refresh()), 'payment_verified' =>
                                PayrollPayment::where('payroll_period_id', $period->id)->where('reference', $data['reference'])
                                    ->where('amount_cents', Money::toCents($data['amount']))->exists()];
                        }
                        $workflow->transition($period, $operation, $data['reason'] ?? '', isset($data['confirmed_net']),
                            isset($data['confirmed_net']) ? (int) $data['confirmed_net'] : null);

                        return ['record' => $this->record($period->refresh())];
                    } finally {
                        $guard->forgetUser();
                    }
                }];
            }));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 422);
        $user = $request->user();
        $canReview = Access::allows($user, 'payroll') || Access::allows($user, 'approve');
        abort_unless($canReview || Access::allows($user, 'pay'), 403);
        $period = PayrollPeriod::findOrFail($id);
        $record = $this->record($period);
        if ($canReview && ! $period->entries()->offset(100)->limit(1)->exists()) {
            $record['batch_net_cents'] = $period->entries()->select('base_pay_cents', 'overtime_pay_cents', 'allowances_cents', 'deductions_cents')
                ->get()->sum(fn ($entry) => $entry->net_pay_cents);
        }

        return response()->json(['record' => $record]);
    }

    private function record(PayrollPeriod $period): array
    {
        return ['id' => (string) $period->id, 'label' => $period->label, 'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(), 'status' => $period->status];
    }
}
