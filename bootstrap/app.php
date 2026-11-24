<?php

use Illuminate\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['payroll.permission' => \App\Http\Middleware\PayrollPermission::class]);

        if ($_SERVER['FLEXY_VERCEL_ENTRYPOINT'] ?? false) {
            $middleware->trustProxies(
                at: ['127.0.0.1', '::1'],
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        if ($_SERVER['FLEXY_VERCEL_ENTRYPOINT'] ?? false) {
            $exceptions->report(function (QueryException $exception): void {
                logger()->error('Database query failed.', ['sqlstate' => $exception->getCode()]);
            })->stop();
        }
    })->create();
