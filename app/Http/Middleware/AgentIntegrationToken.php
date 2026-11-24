<?php

namespace App\Http\Middleware;

use App\Services\Payroll\AgentTokenService;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class AgentIntegrationToken
{
    public function __construct(private AgentTokenService $tokens) {}

    public function handle(Request $request, Closure $next, string $operation = 'bearer'): Response
    {
        if ($operation === 'bearer') {
            $user = $this->tokens->identify($request->bearerToken());
            $request->setUserResolver(fn () => $user);
        } else {
            $this->throttle($request, $operation);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function throttle(Request $request, string $operation): void
    {
        $limiter = new RateLimiter(Cache::store('database'));
        $source = hash('sha256', $request->ip() ?? 'unknown');
        $this->limit($limiter, "agent-api:$operation:ip:$source", $operation === 'login' ? 30 : 120);

        $email = $request->json('email');
        if ($operation === 'login' && is_string($email) && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $account = hash('sha256', strtolower($email));
            $this->limit($limiter, "agent-api:login:account:$account", 5);
        }
    }

    private function limit(RateLimiter $limiter, string $key, int $max): void
    {
        if ($limiter->hit($key, 60) > $max) {
            abort(429, '', ['Retry-After' => (string) max(1, $limiter->availableIn($key))]);
        }
    }
}
