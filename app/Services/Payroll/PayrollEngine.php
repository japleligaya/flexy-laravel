<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonPeriod;

final class PayrollEngine
{
    public function employees(PayrollPeriod $period)
    {
        return Employee::where(function ($q) {
            $q->where('is_active', true)->orWhereNotNull('ended_on');
        })
            ->where(fn ($q) => $q->whereNull('hired_on')->orWhereDate('hired_on', '<=', $period->ends_on))
            ->where(fn ($q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $period->starts_on))
            ->orderBy('id')->get();
    }

    public function calculate(Employee $employee, PayrollPeriod $period, array $overrides = [], ?array $simulationPolicy = null): array
    {
        if ($simulationPolicy !== null && ($simulationPolicy['compliance'] ?? null) !== 'demo') {
            throw new \InvalidArgumentException('A temporary preview policy must be a simulation.');
        }
        $policy = $simulationPolicy ?? PayrollSetting::forDate($period->starts_on->toDateString());
        $errors = [];
        $warnings = [];
        $lines = [];
        $totals = ['base_pay_cents' => 0, 'overtime_pay_cents' => 0, 'allowances_cents' => 0, 'deductions_cents' => 0];
        if ($simulationPolicy === null && PayrollSetting::get()->contains(fn ($s) => isset($s->policy['effective_on']) && $s->policy['effective_on'] > $period->starts_on->toDateString() && $s->policy['effective_on'] <= $period->ends_on->toDateString())) {
            $errors[] = 'policy: company rules change within this cutoff; confirm one applicable period policy before approval';
        }
        $rates = $employee->salaryRates()->orderByDesc('effective_on')->get();
        $attendance = $employee->attendanceRecords()->whereDate('work_date', '>=', $period->starts_on)->whereDate('work_date', '<=', $period->ends_on)->orderBy('work_date')->get();
        $adjustments = PayrollAdjustment::where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', $employee->id))
            ->whereDate('effective_on', '<=', $period->ends_on)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $period->starts_on))
            ->where(fn ($q) => $q->where('recurring', true)->orWhereDate('effective_on', '>=', $period->starts_on))->orderBy('id')->get();
        foreach (['effective_on', 'frequency', 'proration', 'workdays', 'monthly_attendance', 'paid_leave', 'ot_approval', 'contribution_timing', 'compliance', 'labor_review'] as $key) {
            if (! array_key_exists($key, $policy)) {
                $errors[] = 'policy:'.$key.' is unconfigured';
            }
        }
        if (! $employee->hired_on) {
            $errors[] = 'employment: confirm actual hire date';
        }
        if ($employee->hired_on?->gt($period->ends_on)) {
            $errors[] = 'employment: hire is after cutoff';
        }
        if ($employee->ended_on?->lt($period->starts_on)) {
            $errors[] = 'employment: employment ended before cutoff';
        }
        if ($employee->hired_on && $employee->ended_on?->lt($employee->hired_on)) {
            $errors[] = 'employment: leaving date precedes hire';
        }
        $workdays = $policy['workdays'] ?? [];
        $frequency = $policy['frequency'] ?? null;
        if (($frequency === 'monthly' && ($policy['contribution_timing'] ?? null) !== 'monthly') || ($frequency === 'semi_monthly' && ! in_array($policy['contribution_timing'] ?? null, ['split', 'last_cutoff'], true))) {
            $errors[] = 'policy: contribution timing must match the configured cutoff frequency';
        }
        if ($period->starts_on->format('Y-m') !== $period->ends_on->format('Y-m')) {
            $errors[] = 'cutoff: cross-month cutoffs require review';
        }
        if ($frequency === 'monthly' && ($period->starts_on->day !== 1 || ! $period->ends_on->isLastOfMonth())) {
            $errors[] = 'cutoff: configured monthly policy requires a complete calendar month';
        }
        if ($frequency === 'semi_monthly' && ! (($period->starts_on->day === 1 && $period->ends_on->day === 15) || ($period->starts_on->day === 16 && $period->ends_on->isLastOfMonth()))) {
            $errors[] = 'cutoff: semi-monthly policy requires 1–15 or 16–month end';
        }
        if ($employee->salaryWarning()) {
            $warnings[] = $employee->salaryWarning();
        }
        $running = BigRational::of(0);
        $rounded = 0;
        $standardHours = (int) ($policy['hours_per_day'] ?? 0);
        if ($standardHours < 1 || $standardHours > 24) {
            $errors[] = 'policy:hours_per_day is unconfigured';
        }
        foreach (CarbonPeriod::create($period->starts_on, $period->ends_on) as $date) {
            if ($employee->hired_on && $date->lt($employee->hired_on)) {
                continue;
            }
            if ($employee->ended_on && $date->gt($employee->ended_on)) {
                continue;
            }
            $day = $date->toDateString();
            $scheduled = in_array($date->dayOfWeekIso, $workdays, true);
            $rate = $rates->first(fn ($r) => $r->effective_on->toDateString() <= $day);
            $record = $attendance->first(fn ($a) => $a->work_date->toDateString() === $day);
            if (! $rate) {
                $errors[] = 'rate: no authoritative rate on '.$day;

                continue;
            }
            if (str_starts_with($rate->reason, 'Legacy backfill')) {
                $warnings[] = 'Confirm backfilled rate history against employment records';
            }
            $daily = $rate->basis === 'daily';
            $requires = $daily || ($policy['monthly_attendance'] ?? null) === 'required';
            if ($scheduled && $requires && ! $record) {
                $errors[] = 'attendance: missing '.$day;
            }
            if ($record && ! $scheduled && ($record->status === 'present' || $record->approved_overtime_minutes > 0)) {
                $errors[] = 'labor: rest day / holiday coverage requires documented adjustment for '.$day;
            }
            $paid = true;
            if ($record?->status === 'absent') {
                $paid = false;
            }
            if ($record?->status === 'leave') {
                $paid = (bool) $record->paid_leave && $record->approved_by && ($policy['paid_leave'] ?? null) === 'approved_only';
                if (! $record->approved_by || ! $record->leave_type) {
                    $errors[] = 'leave: classification and approval required for '.$day;
                }
            }
            if ($daily) {
                $paid = $paid && $scheduled && $record && in_array($record->status, ['present', 'leave'], true);
            }
            $divisor = 1;
            $earning = BigRational::of(0);
            if ($daily) {
                if ($paid) {
                    $earning = BigRational::of($rate->amount_cents);
                }
            } elseif ($rate->basis === 'monthly') {
                if (($policy['proration'] ?? null) === 'calendar_days') {
                    $divisor = $date->daysInMonth;
                } elseif (($policy['proration'] ?? null) === 'scheduled_workdays') {
                    $divisor = 0;
                    foreach (CarbonPeriod::create($date->copy()->startOfMonth(), $date->copy()->endOfMonth()) as $d) {
                        if (in_array($d->dayOfWeekIso, $workdays, true)) {
                            $divisor++;
                        }
                    }
                    if (! $scheduled) {
                        $paid = false;
                    }
                } else {
                    $errors[] = 'policy: monthly proration must be explicitly configured';
                    $paid = false;
                }
                if ($paid && $divisor > 0) {
                    $earning = BigRational::of($rate->amount_cents)->dividedBy($divisor);
                }
            } else {
                $errors[] = 'rate: unsupported basis';
            }
            $running = $running->plus($earning);
            $next = $running->toScale(0, RoundingMode::HalfUp)->toInt();
            $amount = $next - $rounded;
            $rounded = $next;
            $lines[] = $this->line('base', 'Base '.$day, $amount, $rate->amount_cents.' centavos / '.$divisor.'; '.($paid ? 'payable' : 'unpaid').'; cumulative HALF_UP', $day,
                ['salary_rate_id' => $rate->id, 'rate_effective_on' => $rate->effective_on->toDateString(), 'attendance_id' => $record?->id, 'status' => $record?->status, 'taxable' => true, 'contributory' => true]);
            $totals['base_pay_cents'] += $amount;
            if ($record && (int) $record->overtime_minutes > (int) $record->approved_overtime_minutes) {
                $warnings[] = 'Unapproved overtime excluded on '.$day;
            }
            if ($record && $record->approved_overtime_minutes > 0) {
                if (! $record->approved_by || ! $record->approved_at || $record->approved_overtime_minutes > $record->overtime_minutes) {
                    $errors[] = 'ot: invalid approval '.$day;

                    continue;
                }
                // Never infer hours from logs. Ordinary scheduled work only; other premiums are gated.
                if (! $scheduled || $record->status !== 'present' || ($policy['labor_review'] ?? null) !== 'ordinary_only' || $standardHours < 1 || (! $daily && empty($policy['monthly_ot_divisor']))) {
                    $errors[] = 'ot: employee coverage / hourly divisor needs review on '.$day;

                    continue;
                }
                $otDivisor = $daily ? 1 : (int) $policy['monthly_ot_divisor'];
                $ot = StatutoryRules::round((int) $rate->amount_cents, (int) $record->approved_overtime_minutes * 125, $otDivisor * $standardHours * 60 * 100);
                $totals['overtime_pay_cents'] += $ot;
                $lines[] = $this->line('overtime', 'Approved ordinary OT '.$day, $ot, 'rate / '.$otDivisor.' / '.$standardHours.' / 60 × '.$record->approved_overtime_minutes.' × 125/100', $day,
                    ['attendance_id' => $record->id, 'salary_rate_id' => $rate->id, 'approved_by' => $record->approved_by, 'taxable' => true, 'contributory' => true, 'official_source' => config('payroll.sources.labor')]);
            }
        }
        $hasSupplement = false;
        foreach ($adjustments as $a) {
            $employmentStart = $employee->hired_on && $employee->hired_on->gt($period->starts_on) ? $employee->hired_on : $period->starts_on;
            $employmentEnd = $employee->ended_on && $employee->ended_on->lt($period->ends_on) ? $employee->ended_on : $period->ends_on;
            if ($a->effective_on->gt($employmentEnd) || ($a->ends_on && $a->ends_on->lt($employmentStart)) || (! $a->recurring && $a->effective_on->lt($employmentStart))) {
                $warnings[] = 'Adjustment #'.$a->id.' excluded because its earning dates do not overlap employment';

                continue;
            }
            if ($a->type !== 'deduction') {
                $hasSupplement = true;
            }
            $bucket = $a->type === 'deduction' ? 'deductions_cents' : 'allowances_cents';
            $totals[$bucket] += (int) $a->amount_cents;
            $lines[] = $this->line($a->type, $a->label, (int) $a->amount_cents, $a->reason, $a->effective_on->toDateString(), ['adjustment_id' => $a->id, 'taxable' => $a->taxable, 'contributory' => $a->contributory, 'recurring' => $a->recurring, 'source_url' => $a->source_url]);
            if (in_array($a->type, ['holiday', 'night', 'thirteenth'], true) && ! $a->source_url) {
                $errors[] = 'labor: official source / coverage review required for adjustment '.$a->id;
            }
        }
        if (($policy['labor_review'] ?? null) !== 'ordinary_only') {
            $errors[] = 'labor: holiday calendar, coverage and special premiums need authorized review';
        }
        $mode = $policy['compliance'] ?? 'strict';
        $history = app(PayrollHistory::class)->summarize($employee, $period);
        $statutory = [];
        $rate = $rates->first(fn ($r) => $r->effective_on->lte($period->starts_on));
        $stable = $rate && $rate->basis === 'monthly' && ! $rates->contains(fn ($r) => $r->effective_on->gt($period->starts_on) && $r->effective_on->lte($period->ends_on));
        $standard = $employee->payroll_classification === 'standard_private_regular' && $stable && $employee->hired_on?->lte($period->starts_on->copy()->startOfMonth()) && (! $employee->ended_on || $employee->ended_on->gte($period->ends_on->copy()->endOfMonth()));
        if ($totals['overtime_pay_cents'] > 0 || $attendance->contains(fn ($a) => $a->status !== 'present')) {
            $standard = false;
        }
        if ($frequency === 'monthly' && $rate && (int) $totals['base_pay_cents'] !== (int) $rate->amount_cents) {
            $standard = false;
        }
        $prior = PayrollEntry::where('employee_id', $employee->id)->whereHas('payrollPeriod', fn ($q) => $q->whereIn('status', ['finalized', 'paid'])->whereDate('ends_on', '<', $period->starts_on)->whereDate('starts_on', '>=', $period->starts_on->copy()->startOfMonth())->whereNotIn('id', PayrollCorrection::where('status', 'approved')->select('payroll_period_id')))->orderBy('id')->get();
        if ($frequency === 'semi_monthly' && $period->starts_on->day === 16 && ($prior->count() !== 1 || ! $prior->first()?->snapshot || ($prior->first()?->snapshot['calculation']['policy']['compliance'] ?? null) === 'demo')) {
            $standard = false;
        }
        $dateCovered = $period->ends_on->toDateString() <= config('payroll.verified_through');
        $gross = $totals['base_pay_cents'] + $totals['overtime_pay_cents'] + $totals['allowances_cents'];
        foreach (['sss', 'philhealth', 'pagibig', 'bir'] as $component) {
            $source = config('payroll.sources.'.$component);
            $value = null;
            $previous = $prior->sum(fn ($e) => (int) ($e->snapshot['calculation']['statutory'][$component]['employee'] ?? 0));
            if ($mode === 'demo') {
                $value = ['employee' => 0, 'employer' => 0, 'status' => 'DEMO EXCLUDED — not statutory compliance'];
                $warnings[] = 'DEMO: '.$component.' excluded explicitly; not for real payroll';
            } elseif ($mode === 'manual' && isset($overrides[$component]) && ! empty($overrides[$component]['reason']) && ! empty($overrides[$component]['actor_id'])) {
                $value = $overrides[$component] + ['status' => 'authorized manual; not automatic compliance'];
            } elseif ($standard && $dateCovered && $period->starts_on->toDateString() >= $source['effective_from'] && ! $hasSupplement && in_array($frequency, ['monthly', 'semi_monthly'], true) && in_array($policy['contribution_timing'] ?? null, ['monthly', 'last_cutoff', 'split'], true)) {
                if ($component === 'sss') {
                    $value = StatutoryRules::sss((int) $rate->amount_cents);
                }
                if ($component === 'philhealth') {
                    $value = StatutoryRules::philhealth((int) $rate->amount_cents);
                }
                if ($value) {
                    $target = $value;
                    $previousEmployer = $prior->sum(fn ($e) => (int) ($e->snapshot['calculation']['statutory'][$component]['employer'] ?? 0));
                    if ($frequency === 'semi_monthly' && $period->ends_on->day === 15) {
                        $split = ($policy['contribution_timing'] ?? null) === 'split';
                        $value['employee'] = $split ? StatutoryRules::round($target['employee'], 1, 2) : 0;
                        $value['employer'] = $split ? StatutoryRules::round($target['employer'], 1, 2) : 0;
                    } else {
                        $value['employee'] = max(0, $target['employee'] - $previous);
                        $value['employer'] = max(0, $target['employer'] - $previousEmployer);
                    }
                    $value['monthly_target'] = $target;
                    $value['previous_employee'] = $previous;
                    if (isset($target['ec'])) {
                        $previousEc = $prior->sum(fn ($e) => (int) ($e->snapshot['calculation']['statutory'][$component]['ec'] ?? 0));
                        $value['ec'] = ($frequency === 'semi_monthly' && $period->ends_on->day === 15) ? (($policy['contribution_timing'] ?? null) === 'split' ? StatutoryRules::round($target['ec'], 1, 2) : 0) : max(0, $target['ec'] - $previousEc);
                    }
                    $value['status'] = 'published table; verified standard classification';
                }
            }
            if ($component === 'bir' && $mode !== 'demo' && ! isset($value) && $standard && $dateCovered && ! $hasSupplement && $period->ends_on->month !== 12 && isset($statutory['sss']['employee'],$statutory['philhealth']['employee'],$statutory['pagibig']['employee'])) {
                $contributions = array_sum(array_column($statutory, 'employee'));
                $value = ['employee' => StatutoryRules::bir(max(0, $gross - $contributions), $frequency ?? 'monthly'), 'employer' => 0, 'taxable_cents' => max(0, $gross - $contributions), 'status' => 'regular table; annualization excluded'];
            }
            if ($value === null) {
                $errors[] = 'statutory:'.$component.' Unconfigured / Needs verification (classification, effective rule, special pay or annualization)';
                $statutory[$component] = ['status' => 'needs_verification', 'source' => $source];

                continue;
            }
            $statutory[$component] = $value + ['source' => $source];
            $totals['deductions_cents'] += (int) $value['employee'];
            $lines[] = $this->line('deduction', strtoupper($component).' employee', (int) $value['employee'], $value['status'].'; '.json_encode($value), $period->ends_on->toDateString(), ['component' => $component, 'official_source' => $source, 'taxable' => false, 'contributory' => false]);
            if ($component !== 'bir') {
                $lines[] = $this->line('employer', strtoupper($component).' employer', (int) ($value['employer'] ?? 0), 'Employer expense; excluded from employee net', $period->ends_on->toDateString(), ['component' => $component, 'official_source' => $source]);
            }
            if (isset($value['ec'])) {
                $lines[] = $this->line('employer', 'SSS employer-only EC', (int) $value['ec'], 'Employer-only EC; excluded from employee deductions', $period->ends_on->toDateString(), ['component' => 'sss_ec', 'official_source' => $source]);
            }
        }
        $net = $gross - $totals['deductions_cents'];
        if ($net < 0) {
            $errors[] = 'money: deductions exceed gross (negative net)';
        }
        if ($gross > 99999999999) {
            $errors[] = 'money: amount exceeds supported limit';
        }
        $fingerprint = hash('sha256', json_encode([$employee->getAttributes(), $rates->toArray(), $attendance->toArray(), $adjustments->toArray(), $policy, $overrides, $prior->map->snapshot->all(), $history, config('payroll')], JSON_THROW_ON_ERROR));

        return ['engine_version' => config('payroll.engine_version'), 'fingerprint' => $fingerprint, 'policy' => $policy, 'history' => $history,
            'lines' => $lines, 'statutory' => $statutory, 'exceptions' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)),
            'totals' => $totals + ['gross_cents' => $gross, 'net_cents' => $net]];
    }

    private function line(string $type, string $label, int $amount, string $basis, string $date, array $meta = []): array
    {
        return ['type' => $type, 'label' => $label, 'amount_cents' => $amount, 'basis' => $basis, 'earning_date' => $date, 'rule_version' => config('payroll.engine_version')] + $meta;
    }
}
