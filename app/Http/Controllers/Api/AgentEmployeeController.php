<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\User;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\Audit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AgentEmployeeController
{
    private const FIELDS = ['employee_number', 'first_name', 'last_name', 'email', 'position', 'department_id',
        'hired_on', 'ended_on', 'payroll_classification', 'is_active'];

    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) && filter_var($id, FILTER_VALIDATE_INT), 422);

        return response()->json(['record' => $this->record(Employee::findOrFail($id))]);
    }

    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $create = $operation === 'create';
        $delete = $operation === 'delete';
        $allowed = $delete ? [] : self::FIELDS;
        $allowed[] = $create ? 'monthly_salary' : 'id';
        $rules = $create ? [] : ['id' => 'required|integer|min:1'];
        if ($stage === 'execute') {
            $allowed[] = 'ticket';
            $rules['ticket'] = ['required', 'string', AgentMutation::TICKET_RULE];
        }
        $body = $request->json()->all();
        abort_if(array_diff(array_keys($body), $allowed) !== [], 422);
        Validator::make($body, $rules)->validate();
        $ticket = $body['ticket'] ?? null;
        unset($body['ticket']);
        ksort($body);
        $actor = $request->user();

        return response()->json($mutations->run($actor, 'people', 'employee-'.$operation, $body, $ticket,
            function (bool $lock) use ($operation, $body, $create, $delete, $actor) {
                $query = Employee::query();
                $target = $create ? null : ($lock ? $query->lockForUpdate() : $query)->findOrFail($body['id']);
                $attrs = [];
                if ($delete) {
                    abort_if($target->hasPayrollHistory() || User::where('employee_id', $target->id)->exists(), 409);
                } else {
                    $incoming = array_intersect_key($body, array_fill_keys(self::FIELDS, true));
                    if (! $create) {
                        abort_if($incoming === [], 422);
                    }
                    $original = $target ? $this->record($target) : [];
                    // For profile edits, keep the legacy salary unchanged and validate dates together.
                    $validated = Validator::make($incoming + $original + [
                        'monthly_salary' => $create ? ($body['monthly_salary'] ?? '0') : '0',
                    ], Employee::rules($target))->validate();
                    if ($create) {
                        abort_unless(Money::toCents((string) $validated['monthly_salary']) === 0, 422);
                    }
                    $attrs = array_intersect_key($validated, $incoming);
                    if (array_key_exists('is_active', $attrs)) {
                        $attrs['is_active'] = $attrs['is_active'] === '1';
                    }
                }

                return [$target ? $this->record($target) : null, $target?->getRawOriginal(), function () use ($operation, $target, $delete, $attrs, $actor) {
                    if ($delete) {
                        $target->delete();
                    } elseif ($target) {
                        $target->update($attrs);
                    } else {
                        $target = Employee::create($attrs);
                    }
                    Audit::record('agent_employee_'.$operation, $target, actorId: $actor->getKey());

                    return $delete ? ['deleted' => (string) $target->id] : ['record' => $this->record($target->refresh())];
                }];
            }));
    }

    private function record(Employee $employee): array
    {
        return [
            'id' => (string) $employee->id,
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'position' => $employee->position,
            'department_id' => $employee->department_id === null ? null : (string) $employee->department_id,
            'email' => $employee->email,
            'hired_on' => $employee->getRawOriginal('hired_on'),
            'ended_on' => $employee->getRawOriginal('ended_on'),
            'payroll_classification' => $employee->payroll_classification,
            'is_active' => $employee->is_active ? '1' : '0',
        ];
    }
}
