<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRate extends Model
{
    protected $fillable = ['employee_id', 'effective_on', 'basis', 'amount_cents', 'reason', 'created_by'];

    protected function casts(): array { return ['effective_on' => 'date']; }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
