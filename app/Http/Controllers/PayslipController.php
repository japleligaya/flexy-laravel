<?php

namespace App\Http\Controllers;

use App\Models\PayrollEntry;
use App\Services\Payroll\Access;
use App\Services\Payroll\Audit;
use Dompdf\Dompdf;
use Dompdf\Options;

class PayslipController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $entries = PayrollEntry::where('employee_id', $user->employee_id ?? 0)->whereNotNull('snapshot')->whereHas('payrollPeriod', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->get();

        return view('payslips.index', compact('entries'));
    }

    public function download(PayrollEntry $payrollEntry)
    {
        $user = auth()->user();
        abort_unless(Access::allows($user, 'export') || ($user->role === 'employee' && $user->employee_id && (int) $user->employee_id === (int) $payrollEntry->employee_id), 403);
        abort_unless(in_array($payrollEntry->payrollPeriod->status, ['finalized', 'paid'], true) && $payrollEntry->snapshot, 422, 'A finalized verified snapshot is required.');
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', storage_path('app/private'));
        if ($_SERVER['FLEXY_VERCEL_ENTRYPOINT'] ?? false) {
            $options->setFontCache(storage_path('app/private'));
            $options->setTempDir(storage_path('app/private'));
        }
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('payslips.pdf', ['entry' => $payrollEntry, 's' => $payrollEntry->snapshot])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        Audit::record('payslip_downloaded', $payrollEntry);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="payslip-'.$payrollEntry->id.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
