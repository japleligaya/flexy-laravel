<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\User;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class AgentSyntheticEmployeeBatchController
{
    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations): JsonResponse
    {
        $this->assertIsolated($request);
        abort_unless($request->isJson() && $request->query() === [], 422);
        $body = $request->json()->all();
        $execute = $stage === 'execute';
        $rules = ['batch_id' => ['required', 'uuid']];
        $max = max(1, min(50, (int) config('app.agent_synthetic_batch_limit', 50)));
        if ($operation === 'create') {
            $rules['count'] = ['required', 'integer', 'min:1', 'max:'.$max];
        } else {
            $rules['ids'] = ['required', 'array', 'list', 'min:1', 'max:'.$max];
            $rules['ids.*'] = ['required', 'integer', 'min:1', 'distinct'];
        }
        if ($operation === 'update') {
            $rules['position'] = ['required', 'string', 'max:80', 'regex:/\ASynthetic [A-Za-z0-9 -]+\z/'];
        }
        if ($execute) {
            $rules['ticket'] = ['required', 'string', AgentMutation::TICKET_RULE];
            $rules['approved_digest'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        }
        $allowed = array_diff(array_keys($rules), ['ids.*']);
        abort_if(array_diff(array_keys($body), $allowed) !== [], 422);
        $args = Validator::make($body, $rules)->validate();
        $ticket = $args['ticket'] ?? null;
        $approved = $args['approved_digest'] ?? null;
        unset($args['ticket'], $args['approved_digest']);
        $args['batch_id'] = strtolower($args['batch_id']);
        if (isset($args['ids'])) {
            $args['ids'] = array_map(intval(...), $args['ids']);
            sort($args['ids'], SORT_NUMERIC);
        }
        if (isset($args['count'])) {
            $args['count'] = (int) $args['count'];
        }
        ksort($args);
        $actor = $request->user();
        if ($execute) {
            $limit = new \Illuminate\Cache\RateLimiter(Cache::store('database'));
            abort_if($limit->hit('agent-synthetic-employee-batch:'.$actor->id, 60) > 30, 429);
            $password = $request->header('X-ADI-Synthetic-Approval');
            abort_unless(is_string($password) && strlen($password) <= 200 && Hash::check($password, $actor->password), 403);
        }
        $prefix = 'ADIG-'.substr(hash('sha256', $actor->id.':'.$args['batch_id']), 0, 20).'-';

        return response()->json($mutations->run($actor, 'people', 'synthetic-employee-'.$operation, $args, $ticket,
            function (bool $lock) use ($operation, $args, $approved, $prefix, $actor) {
                $query = Employee::query()->where('employee_number', 'like', $prefix.'%')->orderBy('id');
                $targets = ($lock ? $query->lockForUpdate() : $query)->get();
                $rows = [];
                if ($operation === 'create') {
                    abort_unless($targets->isEmpty(), 409);
                    foreach (range(1, $args['count']) as $ordinal) {
                        $record = [
                            'employee_number' => $prefix.str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT),
                            'first_name' => 'Synthetic',
                            'last_name' => 'Employee '.$ordinal,
                            'position' => 'Synthetic '.['Analyst', 'Coordinator', 'Assistant'][($ordinal - 1) % 3],
                            'monthly_salary' => '0',
                            'is_active' => '0',
                        ];
                        $validated = Validator::make($record, Employee::rules())->validate();
                        unset($validated['monthly_salary']);
                        $rows[] = $validated + ['monthly_salary_cents' => 0];
                    }
                } else {
                    $targets = $targets->filter(fn (Employee $row) => in_array($row->id, $args['ids'], true))->values();
                    abort_unless($targets->count() === count($args['ids']), 404);
                    foreach ($targets as $row) {
                        abort_unless($row->first_name === 'Synthetic' && ! $row->is_active &&
                            (int) $row->monthly_salary_cents === 0 && $row->email === null, 409);
                        abort_if($operation === 'delete' &&
                            ($row->hasPayrollHistory() || User::where('employee_id', $row->id)->exists()), 409);
                        if ($operation === 'update') {
                            Validator::make([
                                'employee_number' => $row->employee_number, 'first_name' => $row->first_name,
                                'last_name' => $row->last_name, 'position' => $args['position'],
                                'monthly_salary' => '0', 'is_active' => $row->is_active ? '1' : '0',
                            ], Employee::rules($row))->validate();
                        }
                        $rows[] = ['id' => (string) $row->id, 'employee_number' => $row->employee_number,
                            'position' => $args['position'] ?? $row->position];
                    }
                }
                $revision = $targets->map(fn (Employee $row) => $row->getRawOriginal())->all();
                $digest = hash('sha256', json_encode([$operation, $args, $rows, $revision], JSON_THROW_ON_ERROR));
                abort_if($lock && ! hash_equals($digest, $approved ?? ''), 409);
                return [['digest' => $digest, 'records' => $rows], $revision,
                    function () use ($operation, $rows, $targets, $actor) {
                        $ids = [];
                        if ($operation === 'create') {
                            foreach ($rows as $row) {
                                $employee = Employee::create($row);
                                $ids[] = (string) $employee->id;
                            }
                        } else {
                            foreach ($targets as $index => $employee) {
                                $ids[] = (string) $employee->id;
                                if ($operation === 'delete') {
                                    $employee->delete();
                                } else {
                                    $employee->update(['position' => $rows[$index]['position']]);
                                }
                            }
                        }
                        $saved = Employee::whereKey($ids)->pluck('position', 'id');
                        abort_unless($operation === 'delete' ? $saved->isEmpty() :
                            $saved->count() === count($ids) && collect($ids)->every(
                                fn ($id, $i) => $saved->get($id) === $rows[$i]['position']
                            ), 409);
                        Audit::record('agent_synthetic_employee_batch_'.$operation, $actor, actorId: $actor->getKey());
                        return ['operation' => $operation, 'verified' => count($ids), 'ids' => $ids];
                    }];
            }));
    }

    private function assertIsolated(Request $request): void
    {
        $db = DB::connection();
        $path = $db->getDatabaseName();
        $fixtureFile = is_string($path) && preg_match('~[/\\\\]adi-agent-gateway[/\\\\]\.tools[/\\\\]acceptance-runs[/\\\\][A-Za-z0-9-]+[/\\\\]payroll\.sqlite\z~', (string) realpath($path));
        abort_unless(config('app.agent_synthetic_batches') === true && ! getenv('VERCEL') &&
            $request->server('REMOTE_ADDR') === '127.0.0.1' && ! $request->hasHeader('Forwarded') &&
            ! $request->hasHeader('X-Forwarded-For') && $db->getDriverName() === 'sqlite' &&
            ! config('database.connections.sqlite.url') &&
            (app()->environment('testing') && $path === ':memory:' ||
                app()->environment(['local', 'testing']) && $fixtureFile && PHP_SAPI === 'cli-server'), 404);
    }
}
