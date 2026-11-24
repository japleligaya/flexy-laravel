<?php

namespace App\Services\Payroll;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class AgentTokenService
{
    public const TOKEN_PATTERN = '/\A[a-f0-9]{64}\z/';

    private const ACCESS_SECONDS = 900;

    private const FAMILY_SECONDS = 604800;

    private const TABLE = 'agent_integration_tokens';

    public function issue(User $user): array
    {
        $this->requireEligible($user);
        $now = now()->timestamp;
        $familyExpiry = $now + self::FAMILY_SECONDS;
        [$tokens, $attributes] = $this->newPair($now, $familyExpiry);

        $expired = DB::table(self::TABLE)->where('family_expires_at', '<=', $now)
            ->orderBy('family_expires_at')->limit(100)->pluck('id');
        if ($expired->isNotEmpty()) {
            DB::table(self::TABLE)->whereIn('id', $expired)->delete();
        }

        DB::table(self::TABLE)->insert($attributes + [
            'user_id' => $user->getKey(),
            'family_expires_at' => $familyExpiry,
        ]);

        return $tokens;
    }

    public function identify(#[SensitiveParameter] ?string $token): User
    {
        abort_unless(is_string($token) && preg_match(self::TOKEN_PATTERN, $token), 401);

        $now = now()->timestamp;
        $userId = DB::table(self::TABLE)->where('access_digest', hash('sha256', $token))
            ->whereNull('revoked_at')->where('access_expires_at', '>', $now)
            ->where('family_expires_at', '>', $now)->value('user_id');
        abort_if($userId === null, 401);

        return $this->requireEligible(User::find($userId));
    }

    public function rotate(#[SensitiveParameter] string $token): array
    {
        $digest = hash('sha256', $token);

        return DB::transaction(function () use ($digest) {
            $current = DB::table(self::TABLE)->where('refresh_digest', $digest)
                ->whereNull('revoked_at')->lockForUpdate()->first();
            $now = now()->timestamp;
            abort_if($current === null || $current->family_expires_at <= $now, 401);
            $this->requireEligible(User::find($current->user_id));

            [$tokens, $attributes] = $this->newPair($now, (int) $current->family_expires_at);
            $rotated = DB::table(self::TABLE)->where('id', $current->id)
                ->where('refresh_digest', $digest)->whereNull('revoked_at')->update($attributes);
            abort_unless($rotated === 1, 401);

            return $tokens;
        }, 3);
    }

    public function revoke(#[SensitiveParameter] string $token): void
    {
        DB::table(self::TABLE)->where('refresh_digest', hash('sha256', $token))
            ->whereNull('revoked_at')->update(['revoked_at' => now()->timestamp]);
    }

    public static function permissions(User $user): array
    {
        $permissions = array_values(array_filter(
            ['dashboard', 'people', 'attendance_read', 'attendance', 'salary', 'read', 'payroll', 'export', 'approve', 'pay', 'settings'],
            fn ($permission) => Access::allows($user, $permission),
        ));
        if ($user->employee_id) {
            $permissions[] = 'own_payslips';
            if ($user->role === 'employee') {
                $permissions[] = 'download_own_payslips';
            }
        }

        return $permissions;
    }

    private function requireEligible(?User $user): User
    {
        abort_if($user === null, 401);
        abort_unless(self::permissions($user) !== [], 403);

        return $user;
    }

    private function newPair(int $now, int $familyExpiry): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $accessExpiry = min($now + self::ACCESS_SECONDS, $familyExpiry);

        return [
            ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $accessExpiry - $now],
            [
                'access_digest' => hash('sha256', $access),
                'refresh_digest' => hash('sha256', $refresh),
                'access_expires_at' => $accessExpiry,
            ],
        ];
    }
}
