<?php

namespace App\Http\Controllers;

use App\Models\PayrollPeriod;
use App\Services\Payroll\AttendanceDraftCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $created = $calculator->generate($payrollPeriod);

        return redirect()->route('payroll-periods.show', $payrollPeriod)
            ->with('success', $created.' attendance-derived draft entry/entries created. Review before finalizing.');
    }
}
