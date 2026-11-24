<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\Payroll\Access;
use App\Services\Payroll\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollSettingsController extends Controller
{
    public function index()
    {
        return view('payroll-settings.index', ['policy' => PayrollSetting::current(), 'sources' => config('payroll.sources'), 'users' => User::orderBy('name')->get(), 'employees' => Employee::orderBy('last_name')->get(), 'adjustments' => PayrollAdjustment::latest()->limit(100)->get(), 'audits' => DB::table('audit_events')->latest('id')->limit(100)->get()]);
    }

    public function setup(Request $request)
    {
        $request->validate(['return_period'=>'nullable|integer|exists:payroll_periods,id']);
        $policy = PayrollSetting::current();
        // A demo configuration is not a pre-confirmed real company policy.
        if (($policy['compliance'] ?? null) === 'demo') $policy = [];
        return view('payroll-settings.setup', ['policy'=>$policy, 'returnPeriod'=>$request->input('return_period')]);
    }

    public function store(Request $r)
    {
        if ($r->boolean('setup_wizard')) {
            $r->validate(['setup_confirmed'=>'accepted','return_period'=>'nullable|integer|exists:payroll_periods,id']);
        }
        $d = $r->validate(['effective_on' => 'required|date', 'company_name' => 'required|string|max:200', 'frequency' => 'required|in:monthly,semi_monthly', 'proration' => 'required|in:calendar_days,scheduled_workdays', 'workdays' => 'required|array|min:1|max:7', 'workdays.*' => 'required|integer|between:1,7|distinct', 'hours_per_day' => 'required|integer|between:1,24', 'monthly_attendance' => 'required|in:required,not_required', 'paid_leave' => 'required|in:approved_only,unpaid', 'ot_approval' => 'required|in:required', 'monthly_ot_divisor' => 'nullable|integer|between:1,366', 'contribution_timing' => 'required|in:monthly,last_cutoff,split', 'compliance' => 'required|in:strict,manual,demo', 'labor_review' => 'required|in:unverified,ordinary_only', 'separate_approver' => 'required|boolean', 'reason' => 'required|string|min:10|max:500']);
        $d['workdays'] = array_map('intval', $d['workdays']);
        if (($d['frequency']==='monthly' && $d['contribution_timing']!=='monthly') || ($d['frequency']==='semi_monthly' && !in_array($d['contribution_timing'],['last_cutoff','split'],true))) {
            throw ValidationException::withMessages(['contribution_timing'=>'Choose the contribution timing that matches your pay schedule.']);
        }
        if (CarbonImmutable::parse($d['effective_on'])->day !== 1) {
            throw ValidationException::withMessages(['effective_on' => 'Choose the first day of the month when these company rules apply. Employee salary changes can still start on any date.']);
        }
        $d['separate_approver'] = (bool) $d['separate_approver'];
        DB::transaction(fn () => PayrollSetting::create(['policy' => $d, 'created_by' => auth()->id()]));

        if ($r->boolean('setup_wizard')) {
            $destination = $r->filled('return_period') ? route('payroll-periods.show', $r->integer('return_period')) : route('payroll-periods.index');
            return redirect($destination)->with('success','Payroll setup saved. Choose your pay dates, then generate payroll. Existing drafts need a preview before their amounts can be updated.');
        }
        return back()->with('success', 'New policy version saved. Recalculate and review affected drafts before approval.');
    }

    public function role(Request $r, User $user)
    {
        $d = $r->validate(['role' => 'required|in:super_admin,hr,payroll_officer,approver,employee', 'employee_id' => 'nullable|integer|exists:employees,id|unique:users,employee_id,'.$user->id, 'reason' => 'required|string|min:10|max:500']);
        DB::transaction(function () use ($user, $d) {
            $admins = User::where('role', 'super_admin')->lockForUpdate()->get();
            if ($user->role === 'super_admin' && $d['role'] !== 'super_admin' && $admins->count() < 2) {
                throw ValidationException::withMessages(['role' => 'Keep at least one Super Admin.']);
            }
            $before = ['role' => $user->role, 'employee_id' => $user->employee_id];
            $user->update(['role' => $d['role'], 'employee_id' => $d['employee_id'] ?? null]);
            Audit::record('role_changed', $user, $before, $d);
        });

        return back()->with('success', 'Role / payslip link updated.');
    }

    public function adjustment(Request $r)
    {
        Access::check('payroll');
        $d = $r->validate(['employee_id' => 'nullable|integer|exists:employees,id', 'type' => 'required|in:allowance,bonus,deduction,holiday,night,thirteenth', 'label' => 'required|string|max:120', 'amount' => 'required|regex:/^\d{1,9}(?:\.\d{1,2})?$/D', 'effective_on' => 'required|date', 'ends_on' => 'nullable|date|after_or_equal:effective_on', 'recurring' => 'required|boolean', 'taxable' => 'required|boolean', 'contributory' => 'required|boolean', 'reason' => 'required|string|min:10|max:500', 'source_url' => 'nullable|url|max:500']);
        $d['amount_cents'] = Money::toCents($d['amount']);
        unset($d['amount']);
        DB::transaction(fn () => PayrollAdjustment::create($d + ['created_by' => auth()->id()]));

        return back()->with('success', 'Documented adjustment saved. Recurring amounts apply once per overlapping cutoff. Preview recalculation before changing any existing drafts.');
    }
}
