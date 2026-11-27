<?php

namespace App\Http\Controllers\Api;

use App\Services\Payroll\RuntimeDatabaseBinding;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AgentRuntimeBindingController
{
    public function __invoke(Request $request, RuntimeDatabaseBinding $binding): Response
    {
        $deployment = config('app.agent_runtime_binding.deployment');
        $expiresAt = filter_var(config('app.agent_runtime_binding.expires_at'), FILTER_VALIDATE_INT);
        $now = now()->timestamp;
        abort_unless(config('app.agent_runtime_binding.production') === true
            && is_string($deployment) && preg_match('/\A[a-z0-9-]{1,150}\.vercel\.app\z/', $deployment)
            && $expiresAt !== false && $expiresAt > $now && $expiresAt <= $now + 1800, 404);
        abort_unless($request->user()->role === 'super_admin', 403);
        abort_unless($request->query() === [] && $request->getContent() === '', 422);

        $limiter = new RateLimiter(Cache::store('database'));
        $key = 'agent-api:runtime-binding:'.$request->user()->getAuthIdentifier();
        if ($limiter->hit($key, 60) > 3) {
            abort(429, '', ['Retry-After' => (string) max(1, $limiter->availableIn($key))]);
        }

        Log::channel('agent_binding')->notice('payrollgo.runtime_binding', $binding->read(DB::connection(), $deployment));

        return response()->noContent(204, [
            'Cache-Control' => 'no-store',
            'Vercel-CDN-Cache-Control' => 'no-store',
        ]);
    }
}
