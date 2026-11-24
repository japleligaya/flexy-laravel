<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollCorrection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'decided_at' => 'datetime'];
    }
}
