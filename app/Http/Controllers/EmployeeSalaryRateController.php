<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Payroll\PayInputChanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $data = $request->validate(PayInputChanges::salaryRules());
        PayInputChanges::addSalary($employee, $data, $request->user()->getAuthIdentifier());

        return redirect()->route('employees.salary-rates.index', $employee)
            ->with('success', 'Effective-dated salary rate added. Previous rates remain unchanged.');
    }
}
