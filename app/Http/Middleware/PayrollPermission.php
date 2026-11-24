<?php

namespace App\Http\Middleware;

use App\Services\Payroll\Access;
use Closure;
use Illuminate\Http\Request;

class PayrollPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        Access::check($permission);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
