<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AgentDepartmentController
{
    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT), 422);

        return response()->json(['record' => $this->record(Department::findOrFail($id))]);
    }

    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $execute = $stage === 'execute';
        $rules = [];
        if ($operation !== 'create') {
            $rules['id'] = ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/', 'integer', 'min:1'];
        }
        if ($operation !== 'delete') {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['description'] = Department::rules()['description'];
        }
        if ($execute) {
            $rules['ticket'] = ['required', 'string', AgentMutation::TICKET_RULE];
        }
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules) !== [], 422);
        $data = Validator::make($body, $rules)->validate();
        $ticket = $data['ticket'] ?? null;
        unset($data['ticket']);
        $user = $request->user();

        return response()->json($mutations->run($user, 'people', $operation, $data, $ticket,
            function (bool $lock) use ($operation, $data, $user) {
                $department = isset($data['id']) ? Department::whereKey($data['id']) : null;
                $target = $department ? ($lock ? $department->lockForUpdate() : $department)->firstOrFail() : null;
                $attributes = array_intersect_key($data, ['name' => true, 'description' => true]);
                if ($operation === 'delete') {
                    abort_if($target->employees()->exists(), 409);
                }
                $this->validateName($attributes, $target);

                return [$target ? $this->record($target) : null, $target?->getRawOriginal(), function () use ($operation, $target, $attributes, $user) {
                    if ($operation === 'delete') {
                        $target->delete();
                    } elseif ($target) {
                        $target->update($attributes);
                    } else {
                        $target = Department::create($attributes);
                    }
                    Audit::record('agent_department_'.$operation, $target, actorId: $user->getKey());

                    return $operation === 'delete' ? ['deleted' => (string) $target->id] : ['record' => $this->record($target)];
                }];
            }));
    }

    private function validateName(array $attributes, ?Department $target): void
    {
        if ($attributes !== []) {
            Validator::make($attributes, ['name' => Department::rules($target)['name']])->validate();
        }
    }

    private function record(Department $department): array
    {
        return ['id' => (string) $department->id, 'name' => $department->name] +
            ($department->description === null ? [] : ['description' => $department->description]);
    }
}
