<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $employees = Employee::with('department')
            ->when($request->filled('q'), function ($query) use ($request) {
                $text = '%'.addcslashes((string) $request->input('q'), '%_\\').'%';
                $query->where(function ($q) use ($text) {
                    $q->where('employee_number', 'like', $text)
                        ->orWhere('first_name', 'like', $text)
                        ->orWhere('last_name', 'like', $text)
                        ->orWhere('email', 'like', $text);
                });
            })
            ->orderBy('last_name')->orderBy('first_name')->paginate(10)->withQueryString();

        return view('employees.index', ['employees' => $employees]);
    }

    public function create(): View
    {
        return view('employees.form', [
            'employee' => new Employee(['is_active' => true]),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data) {
            $employee = Employee::create($data);
            $effective = $request->input('initial_effective_on') ?: ($data['hired_on'] ?? null);
            if ($effective && $data['monthly_salary_cents'] > 0) {
                $employee->salaryRates()->create(['effective_on' => $effective, 'basis' => $request->input('initial_basis', 'monthly'),
                    'amount_cents' => $data['monthly_salary_cents'], 'reason' => 'Initial rate confirmed with employee creation', 'created_by' => auth()->id()]);
            }
        });

        return redirect()->route('employees.index')->with('success', 'Employee created.');
    }

    public function edit(Employee $employee): View
    {
        return view('employees.form', [
            'employee' => $employee,
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request, $employee);
        if ((int) $data['monthly_salary_cents'] !== (int) $employee->monthly_salary_cents) {
            throw ValidationException::withMessages(['monthly_salary' => 'Use salary rate history to change pay with an effective date. Legacy display cannot change payroll.']);
        }
        DB::transaction(fn () => $employee->update($data));

        return redirect()->route('employees.index')->with('success', 'Employee updated.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        if ($employee->payrollEntries()->exists() || $employee->attendanceRecords()->exists() || $employee->salaryRates()->exists() || PayrollAdjustment::where('employee_id', $employee->id)->exists()) {
            return back()->with('error', 'Employee has payroll/attendance/salary-rate history. Set employee to inactive instead.');
        }
        $employee->delete();

        return back()->with('success', 'Employee deleted.');
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:32', Rule::unique('employees', 'employee_number')->ignore($employee)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employee)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position' => ['required', 'string', 'max:255'],
            'monthly_salary' => ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D'],
            'hired_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'payroll_classification' => ['sometimes', Rule::in(['unverified', 'standard_private_regular'])],
            'initial_effective_on' => ['nullable', 'date'],
            'initial_basis' => ['sometimes', Rule::in(['daily', 'monthly'])],
            'is_active' => ['required', Rule::in(['0', '1'])],
        ]);
        $data['monthly_salary_cents'] = Money::toCents((string) $data['monthly_salary']);
        unset($data['initial_effective_on'], $data['initial_basis']);
        $data['is_active'] = $data['is_active'] === '1';
        unset($data['monthly_salary']);

        return $data;
    }
}
