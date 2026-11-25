<?php

namespace App\Services\Payroll;

use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PolicyChanges
{
    public static function rules(): array
    {
        return ['effective_on' => 'required|date', 'company_name' => 'required|string|max:200', 'frequency' => 'required|in:monthly,semi_monthly', 'proration' => 'required|in:calendar_days,scheduled_workdays', 'workdays' => 'required|array|min:1|max:7', 'workdays.*' => 'required|integer|between:1,7|distinct', 'hours_per_day' => 'required|integer|between:1,24', 'monthly_attendance' => 'required|in:required,not_required', 'paid_leave' => 'required|in:approved_only,unpaid', 'ot_approval' => 'required|in:required', 'monthly_ot_divisor' => 'nullable|integer|between:1,366', 'contribution_timing' => 'required|in:monthly,last_cutoff,split', 'compliance' => 'required|in:strict,manual,demo', 'labor_review' => 'required|in:unverified,ordinary_only', 'separate_approver' => 'required|boolean', 'reason' => 'required|string|min:10|max:500'];
    }

    public static function normalize(array $d): array
    {
        $d['workdays'] = array_map('intval', $d['workdays']);
        if (($d['frequency']==='monthly' && $d['contribution_timing']!=='monthly') || ($d['frequency']==='semi_monthly' && !in_array($d['contribution_timing'],['last_cutoff','split'],true))) {
            throw ValidationException::withMessages(['contribution_timing'=>'Choose the contribution timing that matches your pay schedule.']);
        }
        if (CarbonImmutable::parse($d['effective_on'])->day !== 1) {
            throw ValidationException::withMessages(['effective_on' => 'Choose the first day of the month when these company rules apply. Employee salary changes can still start on any date.']);
        }
        $d['separate_approver'] = (bool) $d['separate_approver'];

        return $d;
    }

    public static function save(array $data, int|string $actor): PayrollSetting
    {
        return DB::transaction(function () use ($data, $actor) {
            DB::table('payroll_locks')->where('id', 1)->lockForUpdate()->first();
            return PayrollSetting::create(['policy' => $data, 'created_by' => $actor]);
        });
    }

    public static function adjustmentRules(): array
    {
        return ['employee_id' => 'nullable|integer|exists:employees,id', 'type' => 'required|in:allowance,bonus,deduction,holiday,night,thirteenth', 'label' => 'required|string|max:120', 'amount' => 'required|regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'effective_on' => 'required|date', 'ends_on' => 'nullable|date|after_or_equal:effective_on', 'recurring' => 'required|boolean', 'taxable' => 'required|boolean', 'contributory' => 'required|boolean', 'reason' => 'required|string|min:10|max:500', 'source_url' => 'nullable|url|max:500'];
    }

    public static function addAdjustment(array $data, int|string $actor): PayrollAdjustment
    {
        $data['amount_cents'] = Money::toCents($data['amount']);
        unset($data['amount']);
        return DB::transaction(fn () => PayrollAdjustment::create($data + ['created_by' => $actor]));
    }
}
