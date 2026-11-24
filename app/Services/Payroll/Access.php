<?php

namespace App\Services\Payroll;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class Access
{
    public static function allows(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->role === 'super_admin') {
            return true;
        }

        return in_array($permission, match ($user->role) {
            'hr' => ['dashboard', 'people', 'attendance_read', 'attendance', 'salary'],
            'payroll_officer' => ['dashboard', 'payroll', 'read', 'export', 'salary', 'attendance_read', 'attendance'],
            'approver' => ['dashboard', 'read', 'approve', 'export', 'pay', 'attendance_read'],
            default => [],
        }, true);
    }

    public static function check(string $permission): void
    {
        abort_unless(self::allows(Auth::user(), $permission), 403);
    }
}
