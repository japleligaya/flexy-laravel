<?php

namespace App\Http\Controllers;

use App\Models\PayrollPeriod;
use App\Services\Payroll\AttendanceDraftCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PayrollAttendancePreviewController extends Controller
{
    public function index(PayrollPeriod $payrollPeriod, AttendanceDraftCalculator $calculator): View
    {
        return view('payroll-periods.attendance-preview', [
            'period' => $payrollPeriod,
            'rows' => $calculator->preview($payrollPeriod),
        ]);
    }

    public function store(Request $request, PayrollPeriod $payrollPeriod, AttendanceDraftCalculator $calculator): RedirectResponse
    {
        $request->validate(['reviewed' => ['accepted']]);
        $created = DB::transaction(function () use ($payrollPeriod, $calculator) {
            $period = PayrollPeriod::whereKey($payrollPeriod->id)->lockForUpdate()->firstOrFail();
            if (!$period->isDraft()) {
                throw ValidationException::withMessages(['period' => 'Only draft periods can generate entries.']);
            }
            $rows = $calculator->preview($period); // calculate again under the draft lock
            $count = 0;
            foreach ($rows as $row) {
                if (!$row['eligible']) continue;
                $period->entries()->create([
                    'employee_id' => $row['employee']->getKey(),
                    'base_pay_cents' => $row['base_pay_cents'],
                    'overtime_pay_cents' => 0,
                    'allowances_cents' => 0,
                    'deductions_cents' => 0,
                    'source' => 'attendance_daily',
                    'notes' => 'Daily-rate draft from '.$row['present_days'].' recorded present day(s). Review all leave, OT, deductions, contributions and tax manually.',
                ]);
                $count++;
            }
            if ($count === 0) {
                throw ValidationException::withMessages(['period' => 'No eligible new daily-rate entries. Configure rates, record attendance, or review existing entries.']);
            }
            return $count;
        });
        return redirect()->route('payroll-periods.show', $payrollPeriod)
            ->with('success', $created.' attendance-derived draft entry/entries created. Review before finalizing.');
    }
}
