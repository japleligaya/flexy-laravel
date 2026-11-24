<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'status', 'notes', 'time_in', 'time_out', 'overtime_minutes', 'approved_overtime_minutes', 'approved_by', 'approved_at', 'paid_leave', 'leave_type'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
