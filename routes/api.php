<?php

use App\Http\Controllers\Api\AgentAuthController;
use App\Http\Controllers\Api\AgentDepartmentController;
use App\Http\Controllers\Api\AgentDirectoryController;
use App\Http\Controllers\Api\AgentEmployeeController;
use App\Http\Controllers\Api\AgentDocumentController;
use App\Http\Controllers\Api\AgentPayrollPeriodController;
use App\Http\Controllers\Api\AgentRoutineController;
use App\Http\Middleware\AgentIntegrationToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/integrations/agent')->group(function () {
    Route::post('/auth/login', [AgentAuthController::class, 'login'])->middleware(AgentIntegrationToken::class.':login');
    Route::post('/auth/refresh', [AgentAuthController::class, 'refresh'])->middleware(AgentIntegrationToken::class.':refresh');
    Route::post('/auth/revoke', [AgentAuthController::class, 'revoke'])->middleware(AgentIntegrationToken::class.':revoke');

    Route::middleware(AgentIntegrationToken::class)->group(function () {
        Route::get('/auth/me', [AgentAuthController::class, 'me']);
    });
    foreach ([
        ['/payroll-periods', AgentPayrollPeriodController::class, 'index', 'read'],
        ['/payroll-periods/{id}', AgentPayrollPeriodController::class, 'show', 'read'],
        ['/capabilities', AgentDirectoryController::class, 'permissions', null],
        ['/dashboard', AgentDirectoryController::class, 'dashboard', 'dashboard'],
        ['/departments', AgentDirectoryController::class, 'departments', 'people'],
        ['/departments/{id}', AgentDepartmentController::class, 'show', 'people'],
        ['/employees', AgentDirectoryController::class, 'employees', 'people'],
        ['/employees/{id}', AgentEmployeeController::class, 'show', 'people'],
        ['/attendance', AgentDirectoryController::class, 'attendance', 'attendance_read'],
        ['/verified-attendance/{id}', AgentRoutineController::class, 'attendance', 'attendance_read'],
        ['/verified-drafts/{id}', AgentRoutineController::class, 'period', 'payroll'],
        ['/simulation', AgentRoutineController::class, 'simulation', 'payroll'],
        ['/salary-rates', AgentDirectoryController::class, 'salaryRates', 'salary'],
        ['/salary-rate-details', AgentDirectoryController::class, 'salaryDetails', 'salary'],
        ['/payroll-entries', AgentDirectoryController::class, 'entries', 'read'],
        ['/payslips', AgentDirectoryController::class, 'payslips', 'own_payslips'],
        ['/reports', AgentPayrollPeriodController::class, 'index', 'export'],
        ['/documents/payslip/{id?}', AgentDocumentController::class, 'payslip', null],
        ['/documents/report/{id}', AgentDocumentController::class, 'report', 'export'],
        ['/corrections', AgentDirectoryController::class, 'corrections', 'approve'],
        ['/settings', AgentDirectoryController::class, 'settings', 'settings'],
        ['/audit-events', AgentDirectoryController::class, 'auditEvents', 'settings'],
    ] as [$path, $controller, $method, $permission]) {
        Route::get($path, [$controller, $method])
            ->middleware(AgentIntegrationToken::class.($permission ? ':bearer,'.$permission : ''));
    }
    Route::post('/departments/{operation}/{stage}', AgentDepartmentController::class)
        ->whereIn('operation', ['create', 'update', 'delete'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class.':bearer,people');
    Route::post('/routine/{operation}/{stage}', AgentRoutineController::class)
        ->whereIn('operation', ['attendance-create', 'attendance-delete', 'period-create', 'period-cancel', 'engine-generate'])
        ->whereIn('stage', ['preview', 'execute'])->middleware(AgentIntegrationToken::class);
    Route::post('/employees/{operation}/{stage}', AgentEmployeeController::class)
        ->whereIn('operation', ['create', 'update', 'delete'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class.':bearer,people');
});
