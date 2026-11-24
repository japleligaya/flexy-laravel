<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeSalaryRateController;
use App\Http\Controllers\PayrollAttendancePreviewController;
use App\Http\Controllers\PayrollEngineController;
use App\Http\Controllers\PayrollEntryController;
use App\Http\Controllers\PayrollPeriodController;
use App\Http\Controllers\PayrollSettingsController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PayrollPreviewController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing.index')->name('landing');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/payroll-setup', [PayrollSettingsController::class, 'setup'])->middleware('payroll.permission:settings')->name('payroll-setup');
    Route::get('/payroll-preview', [PayrollPreviewController::class, 'index'])->middleware('payroll.permission:payroll')->name('payroll-preview');
    Route::post('/payroll-periods/{payrollPeriod}/review-ready', [PayrollEngineController::class, 'reviewReady'])->middleware('payroll.permission:payroll')->name('payroll-engine.review-ready');
    Route::get('/my-payslips', [PayslipController::class, 'index'])->name('payslips.index');
    Route::get('/payslips/{payrollEntry}/pdf', [PayslipController::class, 'download'])->name('payslips.download');
    Route::get('/payroll-settings', [PayrollSettingsController::class, 'index'])->middleware('payroll.permission:settings')->name('payroll-settings.index');
    Route::post('/payroll-settings', [PayrollSettingsController::class, 'store'])->middleware('payroll.permission:settings')->name('payroll-settings.store');
    Route::post('/payroll-settings/users/{user}', [PayrollSettingsController::class, 'role'])->middleware('payroll.permission:settings')->name('payroll-settings.role');
    Route::post('/payroll-adjustments', [PayrollSettingsController::class, 'adjustment'])->middleware('payroll.permission:payroll')->name('payroll-adjustments.store');
    Route::post('/attendance/{attendanceRecord}/approve', [AttendanceController::class, 'approve'])->middleware('payroll.permission:approve')->name('attendance.approve');
    Route::post('/payroll-periods/{payrollPeriod}/generate', [PayrollEngineController::class, 'generate'])->middleware('payroll.permission:payroll')->name('payroll-engine.generate');
    Route::get('/payroll-entries/{payrollEntry}/recalculate', [PayrollEngineController::class, 'preview'])->middleware('payroll.permission:payroll')->name('payroll-engine.preview');
    Route::post('/payroll-entries/{payrollEntry}/recalculate', [PayrollEngineController::class, 'recalculate'])->middleware('payroll.permission:payroll')->name('payroll-engine.recalculate');
    Route::post('/payroll-entries/{payrollEntry}/review', [PayrollEngineController::class, 'review'])->middleware('payroll.permission:payroll')->name('payroll-engine.review');
    Route::post('/payroll-entries/{payrollEntry}/reopen', [PayrollEngineController::class, 'reopen'])->middleware('payroll.permission:payroll')->name('payroll-engine.reopen');
    Route::post('/payroll-entries/{payrollEntry}/statutory', [PayrollEngineController::class, 'override'])->middleware('payroll.permission:approve')->name('payroll-engine.override');
    Route::post('/payroll-periods/{payrollPeriod}/workflow/{action}', [PayrollEngineController::class, 'transition'])->name('payroll-engine.transition');
    Route::post('/payroll-periods/{payrollPeriod}/payments', [PayrollEngineController::class, 'payment'])->middleware('payroll.permission:pay')->name('payroll-engine.payment');
    Route::post('/payroll-periods/{payrollPeriod}/correction', [PayrollEngineController::class, 'correction'])->middleware('payroll.permission:approve')->name('payroll-engine.correction');
    Route::post('/payroll-corrections/{correction}/decision', [PayrollEngineController::class, 'correctionDecision'])->middleware('payroll.permission:approve')->name('payroll-engine.correction-decision');
    Route::post('/payroll-corrections/{correction}/replacement', [PayrollEngineController::class, 'replacement'])->middleware('payroll.permission:payroll')->name('payroll-engine.replacement');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('/app', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('employees', EmployeeController::class)->except(['show']);
    Route::get('/employees/{employee}/salary-rates', [EmployeeSalaryRateController::class, 'index'])->name('employees.salary-rates.index');
    Route::post('/employees/{employee}/salary-rates', [EmployeeSalaryRateController::class, 'store'])->name('employees.salary-rates.store');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::delete('/attendance/{attendanceRecord}', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
    Route::get('/payroll-periods', [PayrollPeriodController::class, 'index'])->name('payroll-periods.index');
    Route::post('/payroll-periods', [PayrollPeriodController::class, 'store'])->name('payroll-periods.store');
    Route::get('/payroll-periods/{payrollPeriod}', [PayrollPeriodController::class, 'show'])->name('payroll-periods.show');
    Route::post('/payroll-periods/{payrollPeriod}/cancel', [PayrollPeriodController::class, 'cancel'])->name('payroll-periods.cancel');
    Route::get('/payroll-periods/{payrollPeriod}/attendance-preview', [PayrollAttendancePreviewController::class, 'index'])->name('payroll-periods.attendance-preview');
    Route::post('/payroll-periods/{payrollPeriod}/attendance-drafts', [PayrollAttendancePreviewController::class, 'store'])->name('payroll-periods.attendance-drafts');
    Route::post('/payroll-periods/{payrollPeriod}/finalize', [PayrollPeriodController::class, 'finalize'])->name('payroll-periods.finalize');
    Route::post('/payroll-periods/{payrollPeriod}/entries', [PayrollEntryController::class, 'store'])->name('payroll-entries.store');
    Route::put('/payroll-periods/{payrollPeriod}/entries/{payrollEntry}', [PayrollEntryController::class, 'update'])->name('payroll-entries.update');
    Route::delete('/payroll-periods/{payrollPeriod}/entries/{payrollEntry}', [PayrollEntryController::class, 'destroy'])->name('payroll-entries.destroy');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/payroll/{payrollPeriod}/csv', [ReportController::class, 'payrollCsv'])->name('reports.payroll.csv');
});

// Server checks cover every legacy module as well as the new engine endpoints.
foreach (Route::getRoutes() as $route) {
    $name = $route->getName() ?? '';
    $permission = match (true) {
        $name === 'dashboard' => 'dashboard',
        str_starts_with($name, 'employees.salary-rates.') => 'salary',
        str_starts_with($name, 'employees.'),str_starts_with($name, 'departments.') => 'people',
        $name === 'attendance.index' => 'attendance_read',
        in_array($name, ['attendance.store', 'attendance.destroy'], true) => 'attendance',
        str_starts_with($name, 'reports.') => 'export',
        $name === 'payroll-periods.finalize' => 'approve',
        in_array($name, ['payroll-periods.index', 'payroll-periods.show'], true) => 'read',
        str_starts_with($name, 'payroll-periods.'),str_starts_with($name, 'payroll-entries.') => 'payroll',
        default => null,
    };
    if ($permission) {
        $route->middleware('payroll.permission:'.$permission);
    }
}
