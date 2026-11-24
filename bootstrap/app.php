<?php

use Illuminate\Foundation\Application;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
        $isAgentApi = fn (Request $request) => $request->is('api/v1/integrations/agent', 'api/v1/integrations/agent/*');

        $exceptions->render(function (\Throwable $exception, Request $request) use ($isAgentApi) {
            if (! $isAgentApi($request)) {
                return null;
            }

            $headers = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getHeaders() : [];
            $status = match (true) {
                $exception instanceof \Illuminate\Validation\ValidationException => 422,
                $exception instanceof \Illuminate\Auth\AuthenticationException => 401,
                $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            [$code, $message] = match ($status) {
                401 => ['unauthenticated', 'Invalid credentials or token.'],
                403 => ['forbidden', 'PayrollGO access denied.'],
                404 => ['not_found', 'Resource not found.'],
                405 => ['method_not_allowed', 'Method not allowed.'],
                422 => ['invalid_input', 'Invalid request parameters.'],
                429 => ['rate_limited', 'Too many requests. Try again later.'],
                default => ['unavailable', 'PayrollGO is unavailable.'],
            };

            return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, [
                ...array_intersect_key($headers, array_flip(['Allow', 'Retry-After'])),
                'Cache-Control' => 'no-store',
            ]);
        });

        $exceptions->report(function (\Throwable $exception) use ($isAgentApi) {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return null;
            }
            if (app()->bound('request') && $isAgentApi(request())) {
                logger()->error('PayrollGO integration request failed.', ['type' => $exception::class]);

                return false;
            }

            return null;
        });

        if ($_SERVER['FLEXY_VERCEL_ENTRYPOINT'] ?? false) {
            $exceptions->report(function (QueryException $exception): void {
                $cause = strtolower($exception->getPrevious()?->getMessage() ?? '');
                $reason = match (true) {
                    str_contains($cause, 'endpoint id is not specified') => 'Neon endpoint identification',
                    str_contains($cause, 'certificate') => 'TLS certificate validation',
                    str_contains($cause, 'authentication failed') => 'database authentication',
                    str_contains($cause, 'could not translate host name') => 'database name resolution',
                    str_contains($cause, 'connection timed out') => 'database connection timeout',
                    default => 'database error',
                };
                logger()->error('Database query failed.', ['sqlstate' => $exception->getCode(), 'reason' => $reason]);
            })->stop();
        }
    })->create();
