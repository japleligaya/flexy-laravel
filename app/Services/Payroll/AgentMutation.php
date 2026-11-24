<?php

namespace App\Services\Payroll;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class AgentMutation
{
    public const TICKET_RULE = 'regex:/\A[0-9]{1,12}\.[a-f0-9]{32}\.[a-f0-9]{64}\z/';

    public function run(User $actor, string $permission, string $operation, array $args, ?string $ticket, callable $prepare): array
    {
        try {
            return DB::transaction(function () use ($actor, $permission, $operation, $args, $ticket, $prepare) {
                $query = User::whereKey($actor->getKey());
                abort_unless(Access::allows(($ticket ? $query->lockForUpdate() : $query)->first(), $permission), 403);
                // Preparation repeats under the same transaction and locks at execution.
                [$before, $revision, $apply] = $prepare($ticket !== null);
                $binding = [$actor->getKey(), $operation, $args, $revision];
                $now = now()->timestamp;
                if ($ticket === null) {
                    $expires = $now + 300;
                    $nonce = bin2hex(random_bytes(16));
                    $signature = $this->sign([...$binding, $expires, $nonce]);

                    return ['ticket' => "$expires.$nonce.$signature", 'target' => $before];
                }
                [$expires, $nonce, $signature] = explode('.', $ticket);
                abort_unless((int) $expires > $now &&
                    hash_equals($this->sign([...$binding, (int) $expires, $nonce]), $signature), 409);
                $receipts = DB::table('agent_department_receipts');
                $expired = (clone $receipts)->where('expires_at', '<=', $now)->orderBy('expires_at')->limit(100)->pluck('nonce');
                if ($expired->isNotEmpty()) {
                    (clone $receipts)->whereIn('nonce', $expired)->delete();
                }
                abort_unless($receipts->insertOrIgnore(['nonce' => $nonce, 'expires_at' => (int) $expires]) === 1, 409);

                return $apply();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409);
        }
    }

    private function sign(array $payload): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503);

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }
}
