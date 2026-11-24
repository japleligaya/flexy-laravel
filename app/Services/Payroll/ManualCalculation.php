<?php

namespace App\Services\Payroll;

use App\Models\PayrollEntry;

/** Manual totals remain an auditable exception; policy, coverage and statutory checks still apply. */
final class ManualCalculation
{
    public function calculate(PayrollEntry $entry): array
    {
        $c = app(PayrollEngine::class)->calculate($entry->employee, $entry->payrollPeriod, $entry->statutory_overrides ?? []);
        $mode = $c['policy']['compliance'] ?? 'strict';
        if (! in_array($mode, ['manual', 'demo'], true)) {
            $c['exceptions'][] = 'manual: authorized manual-compliance policy is required';
        }
        if ($mode === 'manual') {
            foreach (['sss', 'philhealth', 'pagibig', 'bir'] as $key) {
                if (! isset($entry->statutory_overrides[$key])) {
                    $c['exceptions'][] = 'manual: authorized '.$key.' amount required for overridden gross';
                }
            }
        }
        $lines = [];
        foreach (['base_pay_cents' => ['base', 'Manual base exception'], 'overtime_pay_cents' => ['overtime', 'Manual overtime exception'], 'allowances_cents' => ['allowance', 'Manual other earnings exception']] as $field => [$type,$label]) {
            $lines[] = ['type' => $type, 'label' => $label, 'amount_cents' => (int) $entry->$field, 'basis' => $entry->notes, 'earning_date' => $entry->payrollPeriod->ends_on->toDateString(), 'rule_version' => config('payroll.engine_version'), 'taxable' => null, 'contributory' => null, 'manual_entry_id' => $entry->id];
        }
        $stat = 0;
        foreach ($c['lines'] as $line) {
            if (isset($line['component'])) {
                $lines[] = $line;
                if ($line['type'] === 'deduction') {
                    $stat += $line['amount_cents'];
                }
            }
        }
        if ($stat > $entry->deductions_cents) {
            $c['exceptions'][] = 'manual: total deductions are less than itemized statutory deductions';
        }
        $lines[] = ['type' => 'deduction', 'label' => 'Other documented manual deductions', 'amount_cents' => max(0, (int) $entry->deductions_cents - $stat), 'basis' => $entry->notes, 'earning_date' => $entry->payrollPeriod->ends_on->toDateString(), 'rule_version' => config('payroll.engine_version')];
        $c['authoritative_reference_lines'] = $c['lines'];
        $c['lines'] = $lines;
        $c['warnings'][] = 'Manual money exception: '.$entry->notes.'; review tax/contribution treatment explicitly';
        $c['totals'] = ['base_pay_cents' => (int) $entry->base_pay_cents, 'overtime_pay_cents' => (int) $entry->overtime_pay_cents, 'allowances_cents' => (int) $entry->allowances_cents, 'deductions_cents' => (int) $entry->deductions_cents, 'gross_cents' => $entry->gross_pay_cents, 'net_cents' => $entry->net_pay_cents];
        $c['fingerprint'] = hash('sha256', json_encode([$c['fingerprint'], $c['totals'], $entry->notes]));

        return $c;
    }
}
