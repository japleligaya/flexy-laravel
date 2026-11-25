<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SalaryRate;
use App\Services\Payroll\AgentMutation;
use App\Services\Payroll\AgentStepUp;
use App\Services\Payroll\PayInputChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AgentPayInputController
{
    public function __invoke(Request $request, string $operation, string $stage, AgentMutation $mutations, AgentStepUp $stepUp): JsonResponse
    {
        abort_unless($request->isJson() && $request->query() === [], 422);
        $salary = $operation === 'salary-rate';
        $rules = ['id' => 'required|integer|min:1'];
        $native = $salary ? PayInputChanges::salaryRules() : array_fill_keys(
            ['approved_overtime_minutes', 'paid_leave', 'leave_type', 'reason'], 'sometimes');
        $execute = $stage === 'execute';
        if ($execute) $rules += ['password' => 'required|string|max:1024', 'ticket' => ['required', 'string', AgentMutation::TICKET_RULE]];
        $body = $request->json()->all();
        abort_if(array_diff_key($body, $rules + $native) !== [], 422);
        $data = Validator::make($body, $rules + $native)->validate();
        $actor = $request->user();
        $ticket = $data['ticket'] ?? null;
        if ($execute) {
            $stepUp->verify($actor, $data['password']);
            $request->json()->remove('password');
        }
        unset($data['password'], $data['ticket']);
        ksort($data);

        return response()->json($mutations->run($actor, $salary ? 'salary' : 'approve', $operation, $data, $ticket,
            function (bool $lock) use ($salary, $data, $actor) {
                $query = $salary ? Employee::query() : AttendanceRecord::query();
                $row = ($lock ? $query->lockForUpdate() : $query)->findOrFail($data['id']);
                if ($salary) {
                    PayInputChanges::validateSalary($row, $data);
                } else {
                    Validator::make($data, PayInputChanges::attendanceRules($row))->validate();
                }

                return [$salary ? ['id' => (string) $row->id] : $this->attendanceRecord($row),
                    $row->getRawOriginal(), function () use ($salary, $row, $data, $actor) {
                        if ($salary) return ['record' => $this->rateRecord(PayInputChanges::addSalary($row, $data, $actor->getKey()))];
                        PayInputChanges::approveAttendance($row, $data, $actor->getKey());

                        return ['record' => $this->attendanceRecord($row->refresh())];
                    }];
            }));
    }

    public function salary(string $id): JsonResponse
    {
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 422);

        return response()->json(['record' => $this->rateRecord(SalaryRate::findOrFail($id))]);
    }

    public function attendance(string $id): JsonResponse
    {
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 422);

        return response()->json(['record' => $this->attendanceRecord(AttendanceRecord::findOrFail($id))]);
    }

    private function rateRecord(SalaryRate $rate): array
    {
        return ['id' => (string) $rate->id, 'employee_id' => (string) $rate->employee_id, 'basis' => $rate->basis,
            'effective_on' => $rate->effective_on->toDateString(), 'amount_cents' => (int) $rate->amount_cents];
    }

    private function attendanceRecord(AttendanceRecord $row): array
    {
        return ['id' => (string) $row->id, 'employee_id' => (string) $row->employee_id,
            'work_date' => $row->work_date->toDateString(), 'status' => $row->status,
            'approved_overtime_minutes' => (int) $row->approved_overtime_minutes,
            'paid_leave' => (bool) $row->paid_leave, 'approved' => $row->approved_at !== null];
    }
}
