<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class AttendanceRecord extends Model
{
    public static function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('is_active', true)],
            'work_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['present', 'absent', 'leave'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'time_in' => ['nullable', 'date'],
            'time_out' => ['nullable', 'date', 'after:time_in'],
            'overtime_minutes' => ['sometimes', 'integer', 'between:0,960'],
            'leave_type' => ['nullable', 'string', 'max:60'],
        ];
    }

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
