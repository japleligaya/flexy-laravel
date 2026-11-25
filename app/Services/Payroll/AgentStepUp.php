<?php

namespace App\Services\Payroll;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class AgentStepUp
{
    public function verify(User $user, #[SensitiveParameter] string $password): void
    {
        $limiter = new RateLimiter(Cache::store('database'));
        $key = 'agent-step-up:'.$user->getKey();
        abort_if($limiter->tooManyAttempts($key, 5), 429, '', [
            'Retry-After' => (string) max(1, $limiter->availableIn($key)),
        ]);
        abort_if($limiter->hit($key, 60) > 5, 429, '', ['Retry-After' => '60']);
        abort_unless(Hash::check($password, $user->getAuthPassword()), 403);
        $limiter->clear($key);
    }
}
