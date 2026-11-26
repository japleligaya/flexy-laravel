<?php

use App\Http\Controllers\Api\AgentAuthController;
use App\Http\Controllers\Api\AgentDepartmentController;
use App\Http\Controllers\Api\AgentDirectoryController;
use App\Http\Controllers\Api\AgentEmployeeController;
use App\Http\Controllers\Api\AgentDocumentController;
use App\Http\Controllers\Api\AgentPreparationController;
use App\Http\Controllers\Api\AgentWorkflowController;
use App\Http\Controllers\Api\AgentEntryWorkflowController;
use App\Http\Controllers\Api\AgentPolicyController;
use App\Http\Controllers\Api\AgentPayInputController;
use App\Http\Controllers\Api\AgentPayrollPeriodController;
use App\Http\Controllers\Api\AgentRoutineController;
use App\Http\Controllers\Api\AgentRuntimeBindingController;
use App\Http\Middleware\AgentIntegrationToken;
use App\Http\Controllers\Api\AgentSyntheticEmployeeBatchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/integrations/agent')->group(function () {
    Route::post('/auth/login', [AgentAuthController::class, 'login'])->middleware(AgentIntegrationToken::class.':login');
    Route::post('/auth/refresh', [AgentAuthController::class, 'refresh'])->middleware(AgentIntegrationToken::class.':refresh');
    Route::post('/auth/revoke', [AgentAuthController::class, 'revoke'])->middleware(AgentIntegrationToken::class.':revoke');

    Route::middleware(AgentIntegrationToken::class)->group(function () {
        Route::get('/auth/me', [AgentAuthController::class, 'me']);
        Route::get('/diagnostics/runtime-binding', AgentRuntimeBindingController::class);
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
        ['/preparation/{id}', AgentPreparationController::class, 'show', 'payroll'],
        ['/attendance-draft-preview/{id}', AgentPreparationController::class, 'attendance', 'payroll'],
        ['/workflow-status/{id}', AgentWorkflowController::class, 'show', null],
        ['/entry-workflow/{id}', AgentEntryWorkflowController::class, 'show', 'payroll'],
        ['/entry-employees', AgentDirectoryController::class, 'entryEmployees', 'payroll'],
        ['/recalculation-preview/{id}', AgentEntryWorkflowController::class, 'preview', 'payroll'],
        ['/verified-salary/{id}', AgentPayInputController::class, 'salary', 'salary'],
        ['/verified-attendance-approval/{id}', AgentPayInputController::class, 'attendance', 'approve'],
        ['/salary-rates', AgentDirectoryController::class, 'salaryRates', 'salary'],
        ['/salary-rate-details', AgentDirectoryController::class, 'salaryDetails', 'salary'],
        ['/payroll-adjustments', AgentDirectoryController::class, 'adjustments', 'payroll'],
        ['/payroll-entries', AgentDirectoryController::class, 'entries', 'read'],
        ['/payslips', AgentDirectoryController::class, 'payslips', 'own_payslips'],
        ['/reports', AgentPayrollPeriodController::class, 'index', 'export'],
        ['/documents/payslip/{id?}', AgentDocumentController::class, 'payslip', null],
        ['/documents/report/{id}', AgentDocumentController::class, 'report', 'export'],
        ['/document-files/payslip/{id}', AgentDocumentController::class, 'payslipFile', null],
        ['/document-files/report/{id}', AgentDocumentController::class, 'reportFile', 'export'],
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
        ->whereIn('operation', ['attendance-create', 'attendance-delete', 'period-create', 'period-cancel', 'engine-generate', 'attendance-generate'])
        ->whereIn('stage', ['preview', 'execute'])->middleware(AgentIntegrationToken::class);
    Route::get('/synthetic/batch-receipts/{kind}/{digest}', [AgentSyntheticEmployeeBatchController::class, 'receipt'])
        ->whereIn('kind', ['employee-create', 'employee-update', 'employee-delete', 'period-create', 'plan'])
        ->middleware(AgentIntegrationToken::class);
    Route::post('/synthetic/operation-plans/{stage}', [AgentSyntheticEmployeeBatchController::class, 'plan'])
        ->whereIn('stage', ['preview', 'execute'])->middleware(AgentIntegrationToken::class.':bearer,people');
    Route::post('/synthetic/payroll-period-batches/{stage}', [AgentSyntheticEmployeeBatchController::class, 'periods'])
        ->whereIn('stage', ['preview', 'execute'])->middleware(AgentIntegrationToken::class.':bearer,payroll');
    Route::post('/synthetic/employee-batches/{operation}/{stage}', AgentSyntheticEmployeeBatchController::class)
        ->whereIn('operation', ['create', 'update', 'delete'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class.':bearer,people');
    Route::post('/employees/{operation}/{stage}', AgentEmployeeController::class)
        ->whereIn('operation', ['create', 'update', 'delete'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class.':bearer,people');
    Route::post('/workflow/{operation}/{stage}', AgentWorkflowController::class)
        ->whereIn('operation', ['submit', 'approve', 'reject', 'finalize', 'payment'])
        ->whereIn('stage', ['preview', 'execute'])->middleware(AgentIntegrationToken::class);
    Route::post('/pay-input/{operation}/{stage}', AgentPayInputController::class)
        ->whereIn('operation', ['salary-rate', 'attendance-approve'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class);
    Route::post('/entry-workflow/{operation}/{stage}', AgentEntryWorkflowController::class)
        ->whereIn('operation', ['review', 'reopen', 'recalculate'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class.':bearer,payroll');
    Route::post('/entry-changes/{operation}/{stage}', [AgentEntryWorkflowController::class, 'change'])
        ->whereIn('operation', ['create', 'generate', 'update', 'delete'])->whereIn('stage', ['preview', 'execute'])
        ->middleware(AgentIntegrationToken::class.':bearer,payroll');
    Route::post('/policy-operations/{operation}/{stage}', AgentPolicyController::class)
        ->whereIn('operation', ['settings', 'adjustment'])->whereIn('stage', ['preview', 'execute'])->middleware(AgentIntegrationToken::class);
    Route::get('/policy-receipts/{operation}/{id}', [AgentPolicyController::class, 'show'])
        ->whereIn('operation', ['settings', 'adjustment'])->middleware(AgentIntegrationToken::class);
});
