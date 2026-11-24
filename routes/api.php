<?php

use App\Http\Controllers\Api\AgentAuthController;
use App\Http\Controllers\Api\AgentPayrollPeriodController;
use App\Http\Middleware\AgentIntegrationToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/integrations/agent')->group(function () {
    Route::post('/auth/login', [AgentAuthController::class, 'login'])->middleware(AgentIntegrationToken::class.':login');
    Route::post('/auth/refresh', [AgentAuthController::class, 'refresh'])->middleware(AgentIntegrationToken::class.':refresh');
    Route::post('/auth/revoke', [AgentAuthController::class, 'revoke'])->middleware(AgentIntegrationToken::class.':revoke');

    Route::middleware(AgentIntegrationToken::class)->group(function () {
        Route::get('/auth/me', [AgentAuthController::class, 'me']);
        Route::get('/payroll-periods', [AgentPayrollPeriodController::class, 'index']);
        Route::get('/payroll-periods/{id}', [AgentPayrollPeriodController::class, 'show']);
    });
});
