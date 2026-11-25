<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\SalaryRate;
use App\Services\Payroll\AgentTokenService;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

final class AgentDirectoryController
{
    public function permissions(Request $request): JsonResponse
    {
        $this->query($request);

        return response()->json(['permissions' => AgentTokenService::permissions($request->user())]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->query($request);

        return response()->json(['statistics' => [
            'active_employees' => Employee::where('is_active', true)->count(),
            'departments' => Department::count(),
            'present_today' => AttendanceRecord::whereDate('work_date', today())->where('status', 'present')->count(),
            'draft_periods' => PayrollPeriod::where('status', 'draft')->count(),
        ]]);
    }

    public function departments(Request $request): JsonResponse
    {
        $query = $this->paging($request, ['q' => 'sometimes|required|string|max:100']);
        $rows = Department::select('id', 'name')->withCount('employees')->orderBy('name');
        if (isset($query['q'])) {
            $rows->whereLike('name', '%'.$query['q'].'%');
        }

        return $this->page($rows, $query, fn (Department $row) => [
            'id' => (string) $row->id, 'name' => $row->name, 'employee_count' => (int) $row->employees_count,
        ]);
    }

    public function employees(Request $request): JsonResponse
    {
        $query = $this->paging($request, [
            'q' => 'sometimes|required|string|max:100',
            'department_id' => $this->idRule(false),
            'active' => 'sometimes|required|in:0,1',
        ]);
        $rows = Employee::select('id', 'employee_number', 'first_name', 'last_name', 'department_id', 'is_active')
            ->orderBy('last_name')->orderBy('first_name');
        if (isset($query['q'])) {
            $search = '%'.$query['q'].'%';
            $rows->where(fn (Builder $q) => $q->whereLike('first_name', $search)
                ->orWhereLike('last_name', $search)->orWhereLike('employee_number', $search));
        }
        if (isset($query['department_id'])) {
            $rows->where('department_id', $query['department_id']);
        }
        if (isset($query['active'])) {
            $rows->where('is_active', $query['active'] === '1');
        }

        return $this->page($rows, $query, fn (Employee $row) => [
            'id' => (string) $row->id, 'employee_number' => $row->employee_number, 'name' => $row->full_name,
            'department_id' => $row->department_id === null ? null : (string) $row->department_id,
            'active' => $row->is_active,
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $query = $this->paging($request, [
            'date' => 'sometimes|required|date_format:Y-m-d',
            'employee_id' => $this->idRule(false),
            'status' => 'sometimes|required|in:present,absent,leave',
        ]);
        $rows = AttendanceRecord::select('id', 'employee_id', 'work_date', 'status', 'approved_at')->orderByDesc('work_date');
        foreach (['date' => 'work_date', 'employee_id' => 'employee_id', 'status' => 'status'] as $key => $column) {
            if (isset($query[$key])) {
                $rows->where($column, $query[$key]);
            }
        }

        return $this->page($rows, $query, fn (AttendanceRecord $row) => [
            'id' => (string) $row->id, 'employee_id' => (string) $row->employee_id,
            'work_date' => $row->work_date->toDateString(), 'status' => $row->status, 'approved' => $row->approved_at !== null,
        ]);
    }

    public function salaryRates(Request $request): JsonResponse
    {
        $query = $this->paging($request, ['employee_id' => $this->idRule()]);
        Employee::select('id')->findOrFail($query['employee_id']);
        $rows = SalaryRate::select('id', 'effective_on', 'basis')->where('employee_id', $query['employee_id'])->orderByDesc('effective_on');

        return $this->page($rows, $query, fn (SalaryRate $row) => [
            'id' => (string) $row->id, 'effective_on' => $row->effective_on->toDateString(), 'basis' => $row->basis,
        ]);
    }

    public function salaryDetails(Request $request): JsonResponse
    {
        $query = $this->paging($request, ['employee_id' => $this->idRule()]);
        Employee::select('id')->findOrFail($query['employee_id']);
        $rows = SalaryRate::select('id', 'effective_on', 'basis', 'amount_cents', 'reason')
            ->where('employee_id', $query['employee_id'])->orderByDesc('effective_on');

        return $this->page($rows, $query, fn (SalaryRate $row) => [
            'id' => (string) $row->id, 'effective_on' => $row->effective_on->toDateString(), 'basis' => $row->basis,
            'amount_cents' => (int) $row->amount_cents, 'reason' => $row->reason,
        ]);
    }

    public function auditEvents(Request $request): JsonResponse
    {
        $query = $this->paging($request);
        $page = (int) ($query['page'] ?? 1);
        $rows = DB::table('audit_events')->orderByDesc('id')->simplePaginate(10,
            ['id', 'action', 'subject_type', 'subject_id', 'created_at'], 'page', $page);

        return response()->json([
            'records' => $rows->getCollection()->map(fn ($event) => [
                'id' => (string) $event->id, 'action' => $event->action, 'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id === null ? null : (string) $event->subject_id,
                'created_at' => $event->created_at,
            ])->all(), 'page' => $page, 'has_more' => $rows->hasMorePages(),
        ]);
    }

    public function entries(Request $request): JsonResponse
    {
        $query = $this->paging($request, ['period_id' => $this->idRule()]);
        $period = PayrollPeriod::select('id')->findOrFail($query['period_id']);

        return $this->page($period->entries()->getQuery()->select('id', 'employee_id', 'reviewed_at'), $query, fn (PayrollEntry $row) => [
            'id' => (string) $row->id, 'employee_id' => (string) $row->employee_id, 'reviewed' => $row->reviewed_at !== null,
        ]);
    }

    public function entryEmployees(Request $request, PayrollEngine $engine): JsonResponse
    {
        $query = $this->paging($request, ['period_id' => $this->idRule(), 'q' => 'sometimes|required|string|max:100']);
        $period = PayrollPeriod::findOrFail($query['period_id']);
        abort_unless($period->isDraft(), 409);
        $rows = $engine->employeeQuery($period)->select('id', 'employee_number', 'first_name', 'last_name');
        foreach (preg_split('/\s+/', trim($query['q'] ?? ''), 6, PREG_SPLIT_NO_EMPTY) as $term) {
            $search = '%'.$term.'%';
            $rows->where(fn (Builder $q) => $q->whereLike('first_name', $search)
                ->orWhereLike('last_name', $search)->orWhereLike('employee_number', $search));
        }

        return $this->page($rows, $query, fn (Employee $employee) => [
            'id' => (string) $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->full_name,
        ]);
    }

    public function payslips(Request $request): JsonResponse
    {
        $query = $this->paging($request);
        $rows = PayrollEntry::select('id', 'payroll_period_id')->where('employee_id', $request->user()->employee_id)
            ->whereNotNull('snapshot')->whereHas('payrollPeriod', fn (Builder $q) => $q->whereIn('status', ['finalized', 'paid']));

        return $this->page($rows, $query, fn (PayrollEntry $row) => [
            'id' => (string) $row->id, 'period_id' => (string) $row->payroll_period_id,
        ]);
    }

    public function corrections(Request $request): JsonResponse
    {
        $query = $this->paging($request, ['period_id' => $this->idRule()]);
        PayrollPeriod::select('id')->findOrFail($query['period_id']);
        $rows = PayrollCorrection::select('id', 'status')->where('payroll_period_id', $query['period_id']);

        return $this->page($rows, $query, fn (PayrollCorrection $row) => [
            'id' => (string) $row->id, 'status' => $row->status,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->query($request);

        return response()->json(['configured' => PayrollSetting::current() !== []]);
    }

    private function idRule(bool $required = true): array
    {
        return [...($required ? [] : ['sometimes']), 'required', 'regex:/\A[1-9][0-9]{0,18}\z/', 'integer', 'min:1'];
    }

    private function query(Request $request, array $rules = []): array
    {
        $query = $request->query();
        abort_if(array_diff_key($query, $rules) !== [], 422);

        return Validator::make($query, $rules)->validate();
    }

    private function paging(Request $request, array $rules = []): array
    {
        return $this->query($request, $rules + ['page' => 'sometimes|required|regex:/\A[1-9][0-9]{0,2}\z/']);
    }

    private function page(Builder $rows, array $query, callable $project): JsonResponse
    {
        $page = (int) ($query['page'] ?? 1);
        $result = $rows->orderByDesc('id')->simplePaginate(10, ['*'], 'page', $page);

        return response()->json([
            'records' => $result->getCollection()->map($project)->all(),
            'page' => $page,
            'has_more' => $result->hasMorePages(),
        ]);
    }
}
