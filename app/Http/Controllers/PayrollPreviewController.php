<?php

namespace App\Http\Controllers;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollEngine;
use App\Support\PayrollExperience;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayrollPreviewController extends Controller
{
    public function index(Request $request, PayrollEngine $engine)
    {
        $rows = [];
        if ($request->boolean('preview')) {
            $data = $request->validate([
                'starts_on'=>'required|date', 'ends_on'=>'required|date|after_or_equal:starts_on',
                'frequency'=>'required|in:monthly,semi_monthly', 'proration'=>'required|in:calendar_days,scheduled_workdays',
                'workdays'=>'required|array|min:1|max:7', 'workdays.*'=>'integer|between:1,7|distinct',
                'monthly_attendance'=>'required|in:required,not_required', 'hours_per_day'=>'required|integer|between:1,24',
                'monthly_ot_divisor'=>'nullable|integer|between:1,366',
            ]);
            if (CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 30) {
                throw ValidationException::withMessages(['ends_on'=>'Choose up to 31 calendar dates for this preview.']);
            }
            $period = new PayrollPeriod(['starts_on'=>$data['starts_on'],'ends_on'=>$data['ends_on'],'status'=>'draft','label'=>'Simulation']);
            $policy = $data + ['company_name'=>'Simulation only', 'effective_on'=>$period->starts_on->copy()->startOfMonth()->toDateString(),
                'paid_leave'=>'approved_only','ot_approval'=>'required','contribution_timing'=>$data['frequency']==='monthly'?'monthly':'split',
                'compliance'=>'demo','labor_review'=>'ordinary_only','separate_approver'=>true];
            $policy['workdays'] = array_map('intval', $data['workdays']);
            foreach ($engine->employees($period) as $employee) {
                $calc = $engine->calculate($employee, $period, [], $policy);
                // Unsaved presentation objects: never call a payroll workflow or persistence method.
                $entry = new PayrollEntry($calc['totals'] + ['employee_id'=>$employee->id,'source'=>'engine','calculation'=>$calc]);
                $entry->setRelation('employee', $employee);
                $rows[] = ['employee'=>$employee,'entry'=>$entry,'presentation'=>PayrollExperience::entry($entry)];
            }
        }
        return view('payroll-periods.simulation', compact('rows'));
    }
}
