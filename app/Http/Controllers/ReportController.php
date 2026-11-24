<?php

namespace App\Http\Controllers;

use App\Models\PayrollPeriod;
use App\Support\Money;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('reports.index', [
            'periods' => PayrollPeriod::withCount('entries')->latest('starts_on')->get(),
        ]);
    }

    public function payrollCsv(PayrollPeriod $payrollPeriod): StreamedResponse
    {
        $filename = 'payroll-period-'.$payrollPeriod->id.'.csv';

        return response()->streamDownload(function () use ($payrollPeriod) {
            $out = fopen('php://output', 'w');
            // Prevent Excel interpreting employee-controlled CSV cells as formulas.
            $safe = static function (?string $value): string {
                $value = (string) $value;

                return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
            };
            fputcsv($out, ['Employee #', 'Name', 'Base Pay (PHP)', 'Overtime (PHP)', 'Allowances (PHP)', 'Deductions (PHP)', 'Gross (PHP)', 'Net (PHP)', 'Period Status', 'Mode', 'Calculation lines (JSON)', 'Exceptions (JSON)']);
            foreach ($payrollPeriod->entries()->with('employee')->lazyById(100) as $entry) {
                fputcsv($out, [
                    $safe($entry->snapshot['employee']['number'] ?? $entry->employee->employee_number), $safe($entry->snapshot['employee']['name'] ?? $entry->employee->full_name),
                    Money::input($entry->base_pay_cents), Money::input($entry->overtime_pay_cents),
                    Money::input($entry->allowances_cents), Money::input($entry->deductions_cents),
                    Money::input($entry->gross_pay_cents), Money::input($entry->net_pay_cents),
                    $payrollPeriod->status,
                    $entry->snapshot['calculation']['policy']['compliance'] ?? $entry->calculation['policy']['compliance'] ?? 'unverified legacy',
                    json_encode($entry->snapshot['calculation']['lines'] ?? $entry->calculation['lines'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($entry->snapshot['calculation']['exceptions'] ?? $entry->calculation['exceptions'] ?? ['Unverified legacy calculation'], JSON_UNESCAPED_UNICODE),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
