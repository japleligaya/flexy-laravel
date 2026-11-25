<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollSetting;
use App\Models\User;
use App\Services\Payroll\Access;
use App\Services\Payroll\Audit;
use App\Services\Payroll\PolicyChanges;
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
        PolicyChanges::save(PolicyChanges::normalize($r->validate(PolicyChanges::rules())), auth()->id());

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
        PolicyChanges::addAdjustment($r->validate(PolicyChanges::adjustmentRules()), auth()->id());

        return back()->with('success', 'Documented adjustment saved. Recurring amounts apply once per overlapping cutoff. Preview recalculation before changing any existing drafts.');
    }
}
