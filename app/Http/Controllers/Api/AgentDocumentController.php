<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Payroll\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentDocumentController
{
    public function payslip(Request $request, ?string $id = null): JsonResponse
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
        $entry = $entries->orderByDesc('payroll_periods.ends_on')->orderByDesc('payroll_entries.id')->firstOrFail();

        return response()->json(['document_id' => (string) $entry->id]);
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
