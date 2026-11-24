<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $latestPeriod = PayrollPeriod::withCount('entries')->latest('starts_on')->first();
        $latestEntries = $latestPeriod
            ? $latestPeriod->entries()->with('employee')->orderByDesc('id')->limit(5)->get()
            : collect();

        return view('dashboard.index', [
            'activeEmployeeCount' => Employee::where('is_active', true)->count(),
            'departmentCount' => Department::count(),
            'attendanceCount' => AttendanceRecord::whereDate('work_date', today())->where('status', 'present')->count(),
            'draftPeriodCount' => PayrollPeriod::where('status', 'draft')->count(),
            'latestPeriod' => $latestPeriod,
            'latestEntries' => $latestEntries,
            'grossCents' => $latestPeriod ? (int) ($latestPeriod->entries()->sum('base_pay_cents') + $latestPeriod->entries()->sum('overtime_pay_cents') + $latestPeriod->entries()->sum('allowances_cents')) : 0,
            'deductionsCents' => $latestPeriod ? (int) $latestPeriod->entries()->sum('deductions_cents') : 0,
        ]);
    }
}
