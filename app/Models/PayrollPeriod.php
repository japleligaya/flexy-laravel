<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $fillable = ['label', 'starts_on', 'ends_on', 'status', 'finalized_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'workflow', 'snapshot', 'correction_of'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'finalized_at' => 'datetime', 'cancelled_at' => 'datetime', 'workflow' => 'array', 'snapshot' => 'array'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
