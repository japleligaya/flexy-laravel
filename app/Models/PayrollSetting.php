<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $fillable = ['policy', 'created_by'];

    protected function casts(): array
    {
        return ['policy' => 'array'];
    }

    public static function current(): array
    {
        return static::latest('id')->first()?->policy ?? [];
    }

    public static function forDate(string $date): array
    {
        $versions = static::orderByDesc('id')->get()->filter(fn ($s) => isset($s->policy['effective_on']) && $s->policy['effective_on'] <= $date)->sortByDesc(fn ($s) => $s->policy['effective_on']);
        $selected = $versions->first();

        return $selected ? ($selected->policy + ['policy_version_id' => $selected->id]) : [];
    }
}
