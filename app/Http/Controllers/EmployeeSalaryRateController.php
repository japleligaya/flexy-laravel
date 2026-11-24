<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeSalaryRateController extends Controller
{
    public function index(Employee $employee): View
    {
        return view('employees.salary-rates', [
            'employee' => $employee,
            'rates' => $employee->salaryRates()->with('author')->orderByDesc('effective_on')->get(),
        ]);
    }

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'effective_on' => ['required', 'date'],
            'basis' => ['required', Rule::in(['daily', 'monthly'])],
            'amount' => ['required', 'regex:/^\\d{1,9}(?:\\.\\d{1,2})?$/D'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $amountCents = Money::toCents((string) $data['amount']);
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Salary rate must be greater than zero.']);
        }
        DB::transaction(function () use ($employee, $data, $amountCents, $request) {
            Employee::whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            if ($employee->salaryRates()->whereDate('effective_on', $data['effective_on'])->exists()) {
                throw ValidationException::withMessages(['effective_on' => 'A rate already exists on that effective date. Choose another date.']);
            }
            $employee->salaryRates()->create([
                'effective_on' => $data['effective_on'],
                'basis' => $data['basis'],
                'amount_cents' => $amountCents,
                'reason' => $data['reason'],
                'created_by' => $request->user()->getAuthIdentifier(),
            ]);
        });
        return redirect()->route('employees.salary-rates.index', $employee)
            ->with('success', 'Effective-dated salary rate added. Previous rates remain unchanged.');
    }
}
