<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\Employee;
use App\Services\Payroll\AttendanceDraftCalculator;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentPreparationController
{
    public function attendance(Request $request, string $id, AttendanceDraftCalculator $calculator): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT), 422);
        $period = PayrollPeriod::findOrFail($id);
        $rows = self::attendanceRows($period, $calculator);

        return response()->json(['record' => [
            'id' => (string) $period->id, 'checked' => count($rows),
            'eligible' => count(array_filter($rows, fn ($row) => $row['eligible'])),
            'existing' => count(array_filter($rows, fn ($row) => $row['already_added'])),
        ]]);
    }

    public static function attendanceRows(PayrollPeriod $period, AttendanceDraftCalculator $calculator): array
    {
        abort_if(Employee::where('is_active', true)->offset(50)->limit(1)->exists(), 422);

        return $calculator->preview($period);
    }

    public function show(Request $request, string $id, PayrollEngine $engine): JsonResponse
    {
        abort_unless($request->query() === [] && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT), 422);
        return response()->json(['record' => self::report(PayrollPeriod::findOrFail($id), $engine)]);
    }

    public static function report(PayrollPeriod $period, PayrollEngine $engine): array
    {
        abort_unless($period->isDraft(), 409);
        $employees = $engine->employeeQuery($period)->limit(11)->get();
        $counts = ['rate_issues' => 0, 'attendance_issues' => 0, 'other_issues' => 0];
        foreach ($employees->take(10) as $employee) {
            $groups = [];
            foreach ($engine->calculate($employee, $period)['exceptions'] as $exception) {
                $group = match (true) {
                    str_starts_with($exception, 'rate:') => 'rate_issues',
                    str_starts_with($exception, 'attendance:'), str_starts_with($exception, 'leave:'),
                    str_starts_with($exception, 'overtime:') => 'attendance_issues',
                    default => 'other_issues',
                };
                $groups[$group] = true;
            }
            foreach (array_keys($groups) as $group) {
                $counts[$group]++;
            }
        }
        $checked = min($employees->count(), 10);
        $partial = $employees->count() > 10;
        $configured = PayrollSetting::forDate($period->starts_on->toDateString()) !== [];

        return [
            'id' => (string) $period->id, 'checked' => $checked, 'partial' => $partial,
            'configured' => $configured, 'ready' => $configured && ! $partial && $checked > 0 && array_sum($counts) === 0,
        ] + $counts;
    }
}
