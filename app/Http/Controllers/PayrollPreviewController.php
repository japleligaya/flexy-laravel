<?php

namespace App\Http\Controllers;

use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollSimulation;
use Illuminate\Http\Request;

class PayrollPreviewController extends Controller
{
    public function index(Request $request, PayrollEngine $engine, PayrollSimulation $simulation)
    {
        $rows = $request->boolean('preview') ? $simulation->calculate($engine, $request->validate(PayrollSimulation::rules()))['rows'] : [];

        return view('payroll-periods.simulation', compact('rows'));
    }
}
