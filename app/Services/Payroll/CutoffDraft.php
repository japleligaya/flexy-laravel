<?php

namespace App\Services\Payroll;

use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CutoffDraft
{
    public static function rules(): array
    {
        return ['label' => 'required|string|max:255', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on'];
    }

    public function checkDates(array $data): void
    {
        if (CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 30) {
            throw ValidationException::withMessages(['ends_on' => 'A payroll cutoff may contain at most 31 calendar dates.']);
        }
        if (PayrollPeriod::whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['starts_on' => 'This period overlaps an existing payroll period.']);
        }
    }

    public function create(array $data): PayrollPeriod
    {
        return DB::transaction(function () use ($data) {
            DB::table('payroll_locks')->where('id', 1)->lockForUpdate()->first();
            $this->checkDates($data);

            return PayrollPeriod::create($data + ['status' => 'draft']);
        }, 3);
    }

    public function cancel(PayrollPeriod $period, string $reason, int|string $actor): void
    {
        if (! $period->isDraft()) {
            throw ValidationException::withMessages(['period' => 'Only draft payroll periods can be cancelled. Finalized and cancelled periods are locked.']);
        }
        $period->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor, 'cancellation_reason' => $reason,
        ]);
    }
}
