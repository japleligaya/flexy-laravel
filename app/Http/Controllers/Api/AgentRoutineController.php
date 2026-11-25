<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\AttendanceDraftCalculator;
use App\Services\Payroll\Audit;
use App\Services\Payroll\CutoffDraft;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollSimulation;
use App\Services\Payroll\PayrollWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AgentRoutineController
{
    private const ID = 'required|integer|min:1';

    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations, CutoffDraft $draft): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $rules = match ($operation) {
            'attendance-create' => AttendanceRecord::rules(),
            'attendance-delete' => ['id' => self::ID],
            'period-create' => CutoffDraft::rules(),
            'period-cancel' => ['id' => self::ID, 'reason' => 'required|string|min:5|max:500'],
            'engine-generate' => ['id' => self::ID, 'prepared' => 'sometimes|required|boolean'],
            'attendance-generate' => ['id' => self::ID],
            default => abort(404),
        };
        $execute = $stage === 'execute';
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules + ($execute ? ['ticket' => true] : [])) !== [], 422);
        $data = Validator::make($body, $rules + ($execute ? ['ticket' => ['required', 'string', AgentMutation::TICKET_RULE]] : []))->validate();
        $ticket = $data['ticket'] ?? null;
        unset($data['ticket']);
        $user = $request->user();
        $permission = in_array($operation, ['attendance-create', 'attendance-delete'], true) ? 'attendance' : 'payroll';

        return response()->json($mutations->run($user, $permission, $operation, $data, $ticket,
            function (bool $lock) use ($operation, $data, $user, $draft) {
                $target = match ($operation) {
                    'attendance-create' => $this->attendanceEmployee($data, $lock),
                    'attendance-delete' => $this->find(new AttendanceRecord, $data['id'], $lock),
                    'period-cancel', 'engine-generate', 'attendance-generate' => $this->find(new PayrollPeriod, $data['id'], $lock),
                    'period-create' => null,
                };
                if ($operation === 'period-create') {
                    $draft->checkDates($data);
                }
                if ($operation === 'period-cancel') {
                    abort_unless($target->isDraft(), 409);
                }
                if ($operation === 'engine-generate') {
                    abort_unless($target->isDraft(), 409);
                    abort_if(app(PayrollEngine::class)->employeeQuery($target)->offset(50)->limit(1)->exists(), 422);
                    if ($data['prepared'] ?? false) {
                        abort_unless(AgentPreparationController::report($target, app(PayrollEngine::class))['ready'], 409);
                    }
                }
                if ($operation === 'attendance-delete') {
                    abort_if($target->approved_at !== null, 409);
                }
                $before = $target instanceof Employee ? ['id' => (string) $target->id] : ($target ? $this->record($target) : null);
                $revision = $target?->getRawOriginal();
                $eligible = null;
                if ($operation === 'attendance-generate') {
                    abort_unless($target->isDraft(), 409);
                    $rows = AgentPreparationController::attendanceRows($target, app(AttendanceDraftCalculator::class));
                    $eligible = AttendanceDraftCalculator::eligibleSignature($rows);
                    abort_if($eligible === [], 422);
                    $revision['attendance_preview'] = $eligible;
                }

                return [$before, $revision, function () use ($operation, $data, $target, $user, $draft, $eligible) {
                    $result = match ($operation) {
                        'attendance-create' => AttendanceRecord::create($data),
                        'period-create' => $draft->create($data),
                        default => $target,
                    };
                    if ($operation === 'period-cancel') {
                        $draft->cancel($result, $data['reason'], $user->getKey());
                    }
                    if ($operation === 'engine-generate') {
                        $created = app(PayrollWorkflow::class)->generate($result, $user);

                        return ['record' => $this->record($result), 'created' => $created, 'entry_count' => $result->entries()->count()];
                    }
                    if ($operation === 'attendance-generate') {
                        $created = app(AttendanceDraftCalculator::class)->generate($result, $eligible);
                        Audit::record('agent_attendance_generate', $result, [], ['created' => $created], $user->getKey());

                        return ['record' => $this->record($result), 'created' => $created, 'entry_count' => $result->entries()->count()];
                    }
                    if ($operation === 'attendance-delete') {
                        $result->delete();
                    }
                    Audit::record('agent_'.str_replace('-', '_', $operation), $result, actorId: $user->getKey());

                    return $operation === 'attendance-delete' ? ['deleted' => (string) $result->id] : ['record' => $this->record($result)];
                }];
            }));
    }

    public function attendance(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT), 422);

        return response()->json(['record' => $this->record(AttendanceRecord::findOrFail($id))]);
    }

    public function period(Request $request, string $id): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT), 422);

        $period = PayrollPeriod::findOrFail($id);

        return response()->json(['record' => $this->record($period), 'entry_count' => $period->entries()->count()]);
    }

    public function simulation(Request $request, PayrollEngine $engine, PayrollSimulation $simulation): JsonResponse
    {
        $rules = PayrollSimulation::rules() + ['page' => 'sometimes|required|integer|between:1,999'];
        abort_if(array_diff_key($request->query(), $rules) !== [], 422);
        $data = Validator::make($request->query(), $rules)->validate();
        $page = (int) ($data['page'] ?? 1);
        unset($data['page']);
        $result = $simulation->calculate($engine, $data, $page);
        $rows = array_map(fn ($row) => [
            'employee_id' => (string) $row['employee']->id,
            'gross_cents' => $row['entry']->gross_pay_cents,
            'net_cents' => $row['entry']->net_pay_cents,
            'exceptions' => count($row['entry']->calculation['exceptions'] ?? []),
        ], $result['rows']);

        return response()->json(['records' => $rows, 'page' => $page, 'has_more' => $result['has_more']]);
    }

    private function attendanceEmployee(array $data, bool $lock): Employee
    {
        $employee = $this->find(new Employee, $data['employee_id'], $lock);
        abort_unless($employee->is_active, 422);
        abort_if(AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $data['work_date'])->exists(), 409);

        return $employee;
    }

    private function find(Model $model, int|string $id, bool $lock): Model
    {
        $query = $model->newQuery();

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    private function record(Model $row): array
    {
        $record = ['id' => (string) $row->id, 'status' => $row->status];

        return $row instanceof PayrollPeriod ? $record + [
            'label' => $row->label, 'starts_on' => $row->starts_on->toDateString(), 'ends_on' => $row->ends_on->toDateString(),
        ] : $record + ['employee_id' => (string) $row->employee_id, 'work_date' => $row->work_date->toDateString()];
    }
}
