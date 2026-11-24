<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\Payroll\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        return view('attendance.index', [
            'records' => AttendanceRecord::with('employee')
                ->when($request->filled('date'), fn ($q) => $q->whereDate('work_date', $request->input('date')))
                ->latest('work_date')->latest('id')->paginate(15)->withQueryString(),
            'employees' => Employee::where('is_active', true)->orderBy('last_name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(AttendanceRecord::rules());
        $existing = AttendanceRecord::where('employee_id', $data['employee_id'])
            ->whereDate('work_date', $data['work_date'])->first();
        if ($existing) {
            return back()->withErrors(['work_date' => 'Attendance already recorded for this employee and date.'])->withInput();
        }
        DB::transaction(fn () => AttendanceRecord::create($data));

        return back()->with('success', 'Attendance saved. This does not automatically change payroll amounts.');
    }

    public function destroy(AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $attendanceRecord->delete();

        return back()->with('success', 'Attendance record removed.');
    }

    public function approve(Request $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $data = $request->validate(['approved_overtime_minutes' => 'required|integer|min:0|max:'.$attendanceRecord->overtime_minutes, 'paid_leave' => 'required|boolean', 'leave_type' => 'nullable|string|max:60', 'reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($data, $attendanceRecord) {
            $before = $attendanceRecord->getAttributes();
            $attendanceRecord->update(['approved_overtime_minutes' => $data['approved_overtime_minutes'], 'paid_leave' => $data['paid_leave'], 'leave_type' => $data['leave_type'] ?? $attendanceRecord->leave_type, 'approved_by' => auth()->id(), 'approved_at' => now()]);
            Audit::record('hours_leave_approved', $attendanceRecord, $before, $data);
        });

        return back()->with('success', 'Explicit OT / leave approval saved. Recalculate affected drafts.');
    }
}
