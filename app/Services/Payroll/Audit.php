<?php

namespace App\Services\Payroll;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(string $action, Model $subject, array $before = [], array $after = [], int|string|null $actorId = null): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actorId ?? auth()->id(), 'action' => $action,
            'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(),
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
