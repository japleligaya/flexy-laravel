<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'ends_on' => 'date', 'recurring' => 'boolean', 'taxable' => 'boolean', 'contributory' => 'boolean'];
    }
}
