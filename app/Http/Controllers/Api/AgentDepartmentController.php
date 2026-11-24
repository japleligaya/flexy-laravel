<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use App\Models\User;
use App\Services\Payroll\Access;
use App\Services\Payroll\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AgentDepartmentController
{
    public function show(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT), 422);

        return response()->json(['record' => $this->record(Department::select('id', 'name')->findOrFail($id))]);
    }

    public function __invoke(Request $request, string $operation, string $stage): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $execute = $stage === 'execute';
        $rules = [];
        if ($operation !== 'create') {
            $rules['id'] = ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/', 'integer', 'min:1'];
        }
        if ($operation !== 'delete') {
            $rules['name'] = ['required', 'string', 'max:255'];
        }
        if ($execute) {
            $rules['ticket'] = ['required', 'string', 'regex:/\A[0-9]{1,12}\.[a-f0-9]{32}\.[a-f0-9]{64}\z/'];
        }
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules) !== [], 422);
        $data = Validator::make($body, $rules)->validate();

        try {
            return DB::transaction(function () use ($request, $operation, $execute, $data) {
                $actor = $request->user()->getKey();
                $userQuery = User::whereKey($actor);
                abort_unless(Access::allows(($execute ? $userQuery->lockForUpdate() : $userQuery)->first(), 'people'), 403);
                $department = isset($data['id']) ? Department::whereKey($data['id']) : null;
                $target = $department ? ($execute ? $department->lockForUpdate() : $department)->firstOrFail() : null;
                $attributes = isset($data['name']) ? ['name' => $data['name']] : [];
                if ($operation === 'delete') {
                    abort_if($target->employees()->exists(), 409);
                }
                $revision = $target ? $target->getRawOriginal() : null;
                if (! $execute) {
                    $this->validateName($attributes, $target);
                    $expires = now()->timestamp + 300;
                    $nonce = bin2hex(random_bytes(16));
                    $signature = $this->sign([$actor, $operation, $data, $revision, $expires, $nonce]);

                    return response()->json(['ticket' => "$expires.$nonce.$signature",
                        'target' => $target ? $this->record($target) : null]);
                }
                [$expires, $nonce, $signature] = explode('.', $data['ticket']);
                unset($data['ticket']);
                $now = now()->timestamp;
                abort_unless((int) $expires > $now &&
                    hash_equals($this->sign([$actor, $operation, $data, $revision, (int) $expires, $nonce]), $signature), 409);
                $receipts = DB::table('agent_department_receipts');
                $expired = (clone $receipts)->where('expires_at', '<=', $now)->orderBy('expires_at')->limit(100)->pluck('nonce');
                if ($expired->isNotEmpty()) {
                    (clone $receipts)->whereIn('nonce', $expired)->delete();
                }
                abort_unless($receipts->insertOrIgnore(['nonce' => $nonce, 'expires_at' => (int) $expires]) === 1, 409);
                $this->validateName($attributes, $target);
                if ($operation === 'delete') {
                    $target->delete();
                    Audit::record('agent_department_delete', $target, actorId: $actor);

                    return response()->json(['deleted' => (string) $target->id]);
                }
                if ($target) {
                    $target->update($attributes);
                } else {
                    $target = Department::create($attributes);
                }
                Audit::record('agent_department_'.$operation, $target, actorId: $actor);

                return response()->json(['record' => $this->record($target)]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409);
        }
    }

    private function validateName(array $attributes, ?Department $target): void
    {
        if ($attributes !== []) {
            Validator::make($attributes, ['name' => Department::rules($target)['name']])->validate();
        }
    }

    private function sign(array $payload): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503);

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }

    private function record(Department $department): array
    {
        return ['id' => (string) $department->id, 'name' => $department->name];
    }
}
