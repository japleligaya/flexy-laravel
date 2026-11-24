<?php

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Support\PayrollExperience;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class PayrollSimulation
{
    public static function rules(): array
    {
        return [
                'starts_on'=>'required|date', 'ends_on'=>'required|date|after_or_equal:starts_on',
                'frequency'=>'required|in:monthly,semi_monthly', 'proration'=>'required|in:calendar_days,scheduled_workdays',
                'workdays'=>'required|array|min:1|max:7', 'workdays.*'=>'integer|between:1,7|distinct',
                'monthly_attendance'=>'required|in:required,not_required', 'hours_per_day'=>'required|integer|between:1,24',
                'monthly_ot_divisor'=>'nullable|integer|between:1,366',
            ];
    }

    public function calculate(PayrollEngine $engine, array $data, ?int $page = null): array
    {
        $rows = [];
        if (CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 30) {
            throw ValidationException::withMessages(['ends_on'=>'Choose up to 31 calendar dates for this preview.']);
        }
        $period = new PayrollPeriod(['starts_on'=>$data['starts_on'],'ends_on'=>$data['ends_on'],'status'=>'draft','label'=>'Simulation']);
        $policy = $data + ['company_name'=>'Simulation only', 'effective_on'=>$period->starts_on->copy()->startOfMonth()->toDateString(),
            'paid_leave'=>'approved_only','ot_approval'=>'required','contribution_timing'=>$data['frequency']==='monthly'?'monthly':'split',
            'compliance'=>'demo','labor_review'=>'ordinary_only','separate_approver'=>true];
        $policy['workdays'] = array_map('intval', $data['workdays']);
        $query = $engine->employeeQuery($period);
        $employees = $page === null ? $query->get() : $query->offset(($page - 1) * 10)->limit(11)->get();
        foreach ($page === null ? $employees : $employees->take(10) as $employee) {
            $calc = $engine->calculate($employee, $period, [], $policy);
            // Unsaved presentation objects: never call a payroll workflow or persistence method.
            $entry = new PayrollEntry($calc['totals'] + ['employee_id'=>$employee->id,'source'=>'engine','calculation'=>$calc]);
            $entry->setRelation('employee', $employee);
            $rows[] = ['employee'=>$employee,'entry'=>$entry,'presentation'=>PayrollExperience::entry($entry)];
        }

        return ['rows' => $rows, 'has_more' => $page !== null && $employees->count() > 10];
    }
}
