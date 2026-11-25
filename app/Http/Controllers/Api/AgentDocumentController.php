<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\PayslipController;
use App\Http\Controllers\ReportController;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AgentDocumentController
{
    public function payslip(Request $request, ?string $id = null): JsonResponse
    {
        return response()->json(['document_id' => (string) $this->findPayslip($request, $id)->id]);
    }

    private function findPayslip(Request $request, ?string $id): PayrollEntry
    {
        $this->validateLookup($request, $id);
        $user = $request->user();
        $entries = PayrollEntry::query()->select('payroll_entries.id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_entries.payroll_period_id')
            ->whereNotNull('payroll_entries.snapshot')->whereIn('payroll_periods.status', ['finalized', 'paid']);
        if ($id === null || ! Access::allows($user, 'export')) {
            abort_unless($user->employee_id && ($user->role === 'employee' || Access::allows($user, 'export')), 403);
            $entries->where('employee_id', $user->employee_id);
        }
        if ($id !== null) {
            $entries->where('payroll_entries.id', $id);
        }
        return $entries->orderByDesc('payroll_periods.ends_on')->orderByDesc('payroll_entries.id')->firstOrFail();
    }

    public function payslipFile(Request $request, string $id, PayslipController $controller): Response
    {
        $entry = $this->findPayslip($request, $id);
        $guard = Auth::guard('web');
        $guard->setUser($request->user());
        try {
            return $controller->download(PayrollEntry::findOrFail($entry->id));
        } finally {
            $guard->forgetUser();
        }
    }

    public function reportFile(Request $request, string $id, ReportController $controller): Response
    {
        $this->validateLookup($request, $id);
        $period = PayrollPeriod::findOrFail($id);
        abort_if($period->entries()->offset(1000)->limit(1)->exists(), 422);

        return $controller->payrollCsv($period);
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $this->validateLookup($request, $id);
        $period = PayrollPeriod::select('id')->findOrFail($id);

        return response()->json(['document_id' => (string) $period->id]);
    }

    private function validateLookup(Request $request, ?string $id): void
    {
        abort_unless($request->query() === [] && ($id === null || (preg_match('/\A[1-9][0-9]{0,18}\z/', $id) &&
            filter_var($id, FILTER_VALIDATE_INT))), 422);
    }
}
