<?php

namespace App\Http\Controllers\Api;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\PayrollPeriod;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\Audit;
use App\Services\Payroll\CutoffDraft;
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
            $rules['position'] = ['required_without:last_name', 'string', 'max:80', 'regex:/\ASynthetic [A-Za-z0-9 -]+\z/'];
        }
        if ($operation === 'update') {
            $rules['last_name'] = ['required_without:position', 'string', 'max:75', 'regex:/\ASynthetic [A-Za-z0-9 -]+\z/'];
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
            $this->approve($request, $actor);
        }
        $prefix = 'ADIG-'.substr(hash('sha256', $actor->id.':'.$args['batch_id']), 0, 20).'-';

        return response()->json($mutations->run($actor, 'people', 'synthetic-employee-'.$operation, $args, $ticket,
            function (bool $lock) use ($operation, $args, $approved, $prefix, $actor, $request) {
                if ($lock) {
                    $this->assertIsolated($request);
                }
                $query = Employee::query()->where('employee_number', 'like', $prefix.'%')->orderBy('id');
                $targets = ($lock ? $query->lockForUpdate() : $query)->get();
                $rows = [];
                if ($operation === 'create') {
                    abort_unless($targets->isEmpty(), 409);
                    $departments = Department::where('name', 'like', 'Synthetic %')->orderBy('id')->limit(8)->pluck('id');
                    foreach (range(1, $args['count']) as $ordinal) {
                        $number = $prefix.str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT);
                        $record = [
                            'employee_number' => $number,
                            'first_name' => 'Synthetic',
                            'last_name' => 'Employee '.['Cedar', 'Harbor', 'Meadow', 'Hill'][($ordinal - 1) % 4].' '.$ordinal,
                            'email' => strtolower($number).'@example.test',
                            'department_id' => $departments->isEmpty() ? null : $departments[($ordinal - 1) % $departments->count()],
                            'payroll_classification' => 'unverified',
                            'position' => 'Synthetic '.['Analyst', 'Coordinator', 'Assistant'][($ordinal - 1) % 3],
                            'monthly_salary' => '0',
                            'is_active' => '0',
                        ];
                        $validated = Validator::make($record, Employee::rules())->validate();
                        unset($validated['monthly_salary']);
                        if ($validated['department_id'] !== null) $validated['department_id'] = (string) $validated['department_id'];
                        $rows[] = $validated + ['monthly_salary_cents' => 0];
                    }
                } else {
                    $targets = $targets->filter(fn (Employee $row) => in_array($row->id, $args['ids'], true))->values();
                    abort_unless($targets->count() === count($args['ids']), 404);
                    foreach ($targets as $row) {
                        abort_unless($row->first_name === 'Synthetic' && ! $row->is_active &&
                            (int) $row->monthly_salary_cents === 0 && ($row->email === null ||
                                $row->email === strtolower($row->employee_number).'@example.test'), 409);
                        abort_if($operation === 'delete' &&
                            ($row->hasPayrollHistory() || User::where('employee_id', $row->id)->exists()), 409);
                        if ($operation === 'update') {
                            Validator::make([
                                'employee_number' => $row->employee_number, 'first_name' => $row->first_name,
                                'last_name' => $args['last_name'] ?? $row->last_name, 'position' => $args['position'] ?? $row->position,
                                'monthly_salary' => '0', 'is_active' => $row->is_active ? '1' : '0',
                            ], Employee::rules($row))->validate();
                        }
                        $rows[] = ['id' => (string) $row->id, 'employee_number' => $row->employee_number,
                            'position' => $args['position'] ?? $row->position, 'last_name' => $args['last_name'] ?? $row->last_name];
                    }
                }
                $revision = $targets->map(fn (Employee $row) => $row->getRawOriginal())->all();
                $digest = hash('sha256', json_encode([$operation, $args, $rows, $revision], JSON_THROW_ON_ERROR));
                abort_if($lock && ! hash_equals($digest, $approved ?? ''), 409);
                return [['digest' => $digest, 'records' => $rows], $revision,
                    function () use ($operation, $rows, $targets, $actor, $digest) {
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
                                    $employee->update(['position' => $rows[$index]['position'], 'last_name' => $rows[$index]['last_name']]);
                                }
                            }
                        }
                        $saved = Employee::whereKey($ids)->get(['id', 'position', 'last_name'])->keyBy('id');
                        abort_unless($operation === 'delete' ? $saved->isEmpty() :
                            $saved->count() === count($ids) && collect($ids)->every(
                                fn ($id, $i) => $saved->get($id)?->position === $rows[$i]['position'] &&
                                    $saved->get($id)?->last_name === $rows[$i]['last_name']
                            ), 409);
                        $receipt = ['operation' => $operation, 'verified' => count($ids), 'ids' => $ids];
                        Audit::record('agent_synthetic_employee_batch_'.$operation, $actor, [],
                            ['digest' => $digest, 'receipt' => $receipt], $actor->getKey());
                        return $receipt;
                    }];
            }));
    }

    public function periods(Request $request, string $stage, AgentMutation $mutations, CutoffDraft $draft): JsonResponse
    {
        $this->assertIsolated($request);
        abort_unless($request->isJson() && $request->query() === [], 422);
        $execute = $stage === 'execute';
        $rules = ['batch_id' => ['required', 'uuid'], 'periods' => ['required', 'array', 'list', 'min:1', 'max:8']];
        if ($execute) {
            $rules['ticket'] = ['required', 'string', AgentMutation::TICKET_RULE];
            $rules['approved_digest'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        }
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules) !== [], 422);
        $input = Validator::make($body, $rules)->validate();
        $periods = [];
        foreach ($input['periods'] as $period) {
            abort_unless(is_array($period) && array_diff_key($period, CutoffDraft::rules()) === [], 422);
            $periods[] = Validator::make($period, [
                'label' => ['required', 'string', 'max:80', 'regex:/\ASynthetic [A-Za-z0-9 -]+\z/'],
                'starts_on' => ['required', 'date_format:Y-m-d'],
                'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            ])->validate();
        }
        usort($periods, fn ($a, $b) => $a['starts_on'] <=> $b['starts_on']);
        foreach ($periods as $i => $period) {
            abort_if($i > 0 && $periods[$i - 1]['ends_on'] >= $period['starts_on'], 422);
        }
        $actor = $request->user();
        if ($execute) {
            $this->approve($request, $actor);
        }
        $args = ['batch_id' => strtolower($input['batch_id']), 'periods' => $periods];
        $digest = hash('sha256', json_encode($args, JSON_THROW_ON_ERROR));
        return response()->json($mutations->run($actor, 'payroll', 'synthetic-period-batch-create', $args, $input['ticket'] ?? null,
            function (bool $lock) use ($periods, $digest, $input, $draft, $actor, $request) {
                if ($lock) {
                    $this->assertIsolated($request);
                    abort_unless(hash_equals($digest, $input['approved_digest'] ?? ''), 409);
                    DB::table('payroll_locks')->where('id', 1)->lockForUpdate()->first();
                }
                foreach ($periods as $period) {
                    $draft->checkDates($period);
                }
                return [['digest' => $digest, 'records' => $periods], null, function () use ($periods, $draft, $actor, $digest) {
                    $ids = [];
                    foreach ($periods as $period) {
                        $saved = $draft->create($period);
                        $ids[] = (string) $saved->id;
                        Audit::record('agent_synthetic_payroll_period_batch_create', $saved, actorId: $actor->getKey());
                    }
                    $saved = PayrollPeriod::whereKey($ids)->get()->keyBy('id');
                    abort_unless(collect($ids)->every(function ($id, $i) use ($saved, $periods) {
                        $row = $saved->get($id);
                        return $row?->status === 'draft' && $row->label === $periods[$i]['label'] &&
                            $row->starts_on->toDateString() === $periods[$i]['starts_on'] &&
                            $row->ends_on->toDateString() === $periods[$i]['ends_on'];
                    }), 409);
                    $verified = $saved->count();
                    $receipt = ['operation' => 'create', 'verified' => $verified, 'ids' => $ids];
                    Audit::record('agent_synthetic_period_batch', $actor, [], ['digest' => $digest, 'receipt' => $receipt], $actor->getKey());
                    return $receipt;
                }];
            }));
    }

    public function plan(Request $request, string $stage, AgentMutation $mutations, CutoffDraft $draft): JsonResponse
    {
        $this->assertIsolated($request);
        abort_unless($request->isJson() && $request->query() === [], 422);
        $execute = $stage === 'execute';
        $rules = [
            'batch_id' => ['required', 'uuid'],
            'tasks' => ['required', 'array', 'list', 'size:2'],
            'tasks.*' => ['required', 'array:operation,count,periods'],
            'tasks.*.operation' => ['required', 'distinct', 'in:employee_create,period_create'],
        ];
        if ($execute) {
            $rules['ticket'] = ['required', 'string', AgentMutation::TICKET_RULE];
            $rules['approved_digest'] = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        }
        $body = $request->json()->all();
        abort_if(array_diff(array_keys($body), ['batch_id', 'tasks', ...($execute ? ['ticket', 'approved_digest'] : [])]) !== [], 422);
        Validator::make($body, $rules)->validate();
        $actor = $request->user();
        abort_unless(\App\Services\Payroll\Access::allows($actor, 'payroll'), 403);
        if ($execute) {
            $this->approve($request, $actor);
        }
        $args = ['batch_id' => strtolower($body['batch_id']), 'tasks' => []];
        foreach ($body['tasks'] as $task) {
            $operation = $task['operation'];
            $field = $operation === 'employee_create' ? 'count' : 'periods';
            abort_unless(count($task) === 2 && array_key_exists($field, $task), 422);
            $args['tasks'][] = ['operation' => $operation, $field => $task[$field]];
        }

        $dispatch = function (array $task, ?array $preview = null) use ($request, $actor, $args, $mutations, $draft) {
            $employee = $task['operation'] === 'employee_create';
            $input = ['batch_id' => $args['batch_id']] + ($employee ? ['count' => $task['count']] : ['periods' => $task['periods']]);
            if ($preview) {
                $input += ['ticket' => $preview['ticket'], 'approved_digest' => $preview['target']['digest']];
            }
            $inner = Request::create('/synthetic/plan-step', 'POST', [], [], [], [
                'REMOTE_ADDR' => $request->server('REMOTE_ADDR'), 'CONTENT_TYPE' => 'application/json',
            ], json_encode($input, JSON_THROW_ON_ERROR));
            $inner->setUserResolver(fn () => $actor);
            if ($preview) {
                $inner->headers->set('X-ADI-Synthetic-Approval', $request->header('X-ADI-Synthetic-Approval'));
            }
            $mode = $preview ? 'execute' : 'preview';
            return ($employee ? $this($inner, 'create', $mode, $mutations) :
                $this->periods($inner, $mode, $mutations, $draft))->getData(true);
        };

        return response()->json($mutations->run($actor, 'people', 'synthetic-atomic-plan', $args, $body['ticket'] ?? null,
            function (bool $lock) use ($args, $body, $dispatch, $actor) {
                $previews = [];
                $records = [];
                $fresh = $actor->newQuery()->whereKey($actor->id);
                abort_unless(\App\Services\Payroll\Access::allows(($lock ? $fresh->lockForUpdate() : $fresh)->first(), 'payroll'), 403);
                foreach ($args['tasks'] as $task) {
                    $preview = $dispatch($task);
                    $previews[] = $preview;
                    $records[] = ['operation' => $task['operation'], 'records' => $preview['target']['records']];
                }
                $digest = hash('sha256', json_encode([$args, $records], JSON_THROW_ON_ERROR));
                abort_if($lock && ! hash_equals($digest, $body['approved_digest'] ?? ''), 409);
                return [['digest' => $digest, 'tasks' => $records], null,
                    function () use ($args, $dispatch, $previews, $digest, $actor) {
                        $tasks = [];
                        foreach ($args['tasks'] as $index => $task) {
                            $tasks[] = ['operation' => $task['operation'], 'result' => $dispatch($task, $previews[$index])];
                        }
                        $receipt = ['operation' => 'plan', 'tasks' => $tasks];
                        Audit::record('agent_synthetic_atomic_plan', $actor, [], ['digest' => $digest, 'receipt' => $receipt], $actor->getKey());
                        return $receipt;
                    }];
            }));
    }

    public function receipt(Request $request, string $kind, string $digest): JsonResponse
    {
        $this->assertIsolated($request);
        abort_unless($request->query() === [] && preg_match('/\A[a-f0-9]{64}\z/', $digest), 422);
        $actor = $request->user();
        [$name, $permissions] = match ($kind) {
            'employee-create', 'employee-update', 'employee-delete' => [
                'agent_synthetic_employee_batch_'.substr($kind, 9), ['people'],
            ],
            'period-create' => ['agent_synthetic_period_batch', ['payroll', 'read']],
            'plan' => ['agent_synthetic_atomic_plan', ['people', 'payroll', 'read']],
            default => abort(404),
        };
        foreach ($permissions as $permission) {
            abort_unless(\App\Services\Payroll\Access::allows($actor, $permission), 403);
        }
        $row = DB::table('audit_events')->where('action', $name)->where('actor_id', $actor->id)
            ->where('after->digest', $digest)->latest('id')->first();
        abort_unless($row, 404);
        $record = json_decode($row->after, true, flags: JSON_THROW_ON_ERROR);
        return response()->json($record['receipt']);
    }

    private function approve(Request $request, User $actor): void
    {
        $limit = new \Illuminate\Cache\RateLimiter(Cache::store('database'));
        abort_if($limit->hit('agent-synthetic-batch:'.$actor->id, 60) > 30, 429);
        $password = $request->header('X-ADI-Synthetic-Approval');
        abort_unless(is_string($password) && strlen($password) <= 200 && Hash::check($password, $actor->password), 403);
    }

    private function assertIsolated(Request $request): void
    {
        $db = DB::connection();
        $path = $db->getDatabaseName();
        $fixtureFile = is_string($path) && preg_match('~[/\\\\]adi-agent-gateway[/\\\\]\.tools[/\\\\]acceptance-runs[/\\\\][A-Za-z0-9-]+[/\\\\]payroll\.sqlite\z~', (string) realpath($path));
        $stop = config('app.agent_synthetic_batch_stop');
        $stopConfigured = is_string($stop) && $stop === dirname((string) realpath($path)).DIRECTORY_SEPARATOR.'stop-writes';
        abort_unless(config('app.agent_synthetic_batches') === true && ! getenv('VERCEL') &&
            $request->server('REMOTE_ADDR') === '127.0.0.1' && ! $request->hasHeader('Forwarded') &&
            ! $request->hasHeader('X-Forwarded-For') && $db->getDriverName() === 'sqlite' &&
            ! config('database.connections.sqlite.url') &&
            (app()->environment('testing') && $path === ':memory:' ||
                app()->environment(['local', 'testing']) && $fixtureFile && PHP_SAPI === 'cli-server' && $stopConfigured), 404);
        if ($stopConfigured) {
            clearstatcache(true, $stop);
            abort_if(file_exists($stop), 503);
        }
    }
}
