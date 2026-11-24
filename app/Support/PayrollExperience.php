<?php

namespace App\Support;

use App\Models\PayrollEntry;
use App\Services\Payroll\Access;

/** Presentation only: no pay amounts, eligibility or workflow rules are changed here. */
final class PayrollExperience
{
    public static function groups(array $problems, ?PayrollEntry $entry = null): array
    {
        $groups = ['Needs Setup' => [], 'Needs Employee Information' => [], 'Needs Compliance Review' => []];
        $simulation = $entry && !$entry->exists;
        foreach ($problems as $raw) {
            $group = 'Needs Compliance Review';
            $key = 'other';
            $title = 'A payroll check is needed';
            $explanation = 'A payroll specialist needs to check this calculation before it can be approved.';
            $permission = 'approve'; $url = $entry && $entry->exists ? '#statutory-'.$entry->id : '#simulation-options'; $action = 'Review this calculation'; $owner = 'Ask your payroll approver to review this calculation.';
            if (str_starts_with($raw, 'policy:')) {
                $group = 'Needs Setup'; $key = 'policy'; $title = 'Confirm your company’s pay rules';
                $explanation = 'Tell us how your company pays employees, including partial months and attendance. We will not choose a company policy for you.';
                $permission = 'settings'; $url = route('payroll-setup'); $action = 'Complete payroll setup'; $owner = 'Ask your Super Admin to complete payroll setup.';
            } elseif (str_starts_with($raw, 'cutoff:')) {
                $group = 'Needs Setup'; $key = 'dates'; $title = 'Check the payroll dates';
                $explanation = 'These dates do not match the company’s pay schedule. Monthly payroll uses the whole month; twice-monthly payroll uses 1–15 or 16–month end. Cancel an incorrect draft before creating its replacement.';
                $permission = 'payroll'; $url = route('payroll-periods.index'); $action = 'Check pay periods'; $owner = 'Ask your Payroll Officer to check the pay period.';
            } elseif (str_starts_with($raw, 'rate:')) {
                $group = 'Needs Employee Information'; $key = 'rate'; $title = 'Confirm the employee’s salary';
                $explanation = 'A dated salary rate is missing or unsupported for some pay dates. Add the agreed rate and its actual start date; you do not need to retype base pay in payroll.';
                $permission = 'salary'; $url = $entry ? route('employees.salary-rates.index', $entry->employee_id) : route('employees.index'); $action = 'Fix salary information'; $owner = 'Ask HR to confirm the employee’s salary and start date.';
            } elseif (str_starts_with($raw, 'employment:')) {
                $group = 'Needs Employee Information'; $key = 'employment'; $title = 'Check the employee’s employment dates';
                $explanation = 'Confirm the hire date and, if applicable, leaving date. Only dates within employment can be included in this payroll.';
                $permission = 'people'; $url = $entry ? route('employees.edit', $entry->employee_id) : route('employees.index'); $action = 'Fix employment dates'; $owner = 'Ask HR to confirm the employee’s employment dates.';
            } elseif (str_starts_with($raw, 'attendance:')) {
                $group = 'Needs Employee Information'; $key = 'attendance'; $title = 'Some attendance dates are missing';
                $explanation = 'Record whether the employee worked, was absent or took leave on the required dates. Missing attendance is not treated as a zero-pay day.';
                $permission = 'attendance_read'; $url = route('attendance.index'); $action = 'Complete attendance'; $owner = 'Ask HR to complete attendance for this period.';
            } elseif (str_starts_with($raw, 'leave:') || str_starts_with($raw, 'ot:')) {
                $group = 'Needs Employee Information'; $key = str_starts_with($raw, 'leave:') ? 'leave' : 'ot';
                $title = $key === 'leave' ? 'Confirm how leave should be paid' : 'Check approved overtime';
                $explanation = 'An authorized reviewer needs to confirm the leave or extra hours and the applicable pay rule. Unapproved extra hours are excluded.';
                $permission = 'attendance_read'; $url = route('attendance.index'); $action = 'Check leave and extra hours'; $owner = 'Ask your payroll approver to check leave or extra hours.';
            } elseif (str_starts_with($raw, 'statutory:')) {
                $key = 'deductions'; $title = 'Government deductions still need checking';
                $explanation = 'SSS, PhilHealth, Pag-IBIG or income tax cannot yet be confirmed for this employee. Take-home pay is not final until the required deductions are verified.';
                $action = 'Review government deductions';
                if (!$entry) { $permission = 'settings'; $url = route('payroll-settings.index').'#compliance-controls'; }
            } elseif (str_starts_with($raw, 'labor:')) {
                $key = 'special-pay'; $title = 'Special pay needs an approved rule';
                $explanation = 'Holiday, rest-day, night work or other special pay needs a documented rule and employee coverage check. It will not be guessed.';
                $permission = 'settings'; $url = route('payroll-settings.index').'#compliance-controls'; $action = 'Check special-pay settings'; $owner = 'Ask your Super Admin and payroll approver to confirm special-pay coverage.';
            } elseif (str_starts_with($raw, 'money:')) {
                $key = 'amount'; $title = 'Check the earnings and deductions';
                $explanation = 'The amounts need checking, for example because deductions are higher than earnings. This payroll cannot be approved with an invalid total.';
                $permission = 'payroll'; $url = $entry ? '#adjustment-'.$entry->id : '#payroll-help'; $action = 'Review pay adjustments'; $owner = 'Ask your Payroll Officer to check the amounts.';
            } elseif (str_starts_with($raw, 'Sources changed:')) {
                $group = 'Needs Employee Information'; $key = 'changed'; $title = 'Employee information has changed';
                $explanation = 'Reopen the review if needed, then preview updated amounts before confirming them. Existing payroll amounts have been preserved.';
                $permission = 'payroll'; $url = $entry ? '#review-'.$entry->id : '#payroll-help'; $action = 'Update this calculation'; $owner = 'Ask your Payroll Officer to update the draft calculation.';
            } elseif (str_starts_with($raw, 'manual:') || str_contains($raw, 'Legacy/manual')) {
                $key = 'manual'; $title = 'A manually entered amount needs verification';
                $explanation = 'An authorized reviewer must check the reason, itemized amounts and government deductions before approving this exception.';
            }
            if ($simulation && ($key==='dates' || $key==='amount' || ($key==='ot' && str_contains($raw,'divisor')))) {
                $url='#simulation-options';$permission='payroll';$action='Change preview choices';
                $explanation='Check the example dates, pay schedule and rate choices above. This preview has not saved or changed any payroll.';
            }
            if (!isset($groups[$group][$key])) {
                $groups[$group][$key] = compact('title', 'explanation', 'url', 'action', 'owner') + ['canFix' => Access::allows(auth()->user(), $permission), 'raw' => []];
            }
            $groups[$group][$key]['raw'][] = $raw;
        }
        return array_filter(array_map('array_values', $groups));
    }

    public static function entry(PayrollEntry $entry, bool $stale = false): array
    {
        $calc = $entry->snapshot['calculation'] ?? $entry->calculation ?? [];
        $problems = $calc['exceptions'] ?? ['Legacy/manual entry needs an itemized verified calculation.'];
        if ($stale) $problems[] = 'Sources changed: reopen review, preview and confirm recalculation.';
        $demo = ($calc['policy']['compliance'] ?? null) === 'demo';
        $deductionsComplete = !$demo && collect(['sss','philhealth','pagibig','bir'])->every(fn($key)=>isset($calc['statutory'][$key]['employee']));
        if (!$demo && !$deductionsComplete && !collect($problems)->contains(fn($problem)=>str_starts_with($problem,'statutory:'))) {
            $problems[] = 'statutory:required deductions have not been confirmed';
        }
        $lines = collect($calc['lines'] ?? []);
        $base = $lines->where('type', 'base');
        $rateIds = collect($calc['authoritative_reference_lines'] ?? $calc['lines'] ?? [])->pluck('salary_rate_id')->filter()->unique();
        $rates = $entry->employee->salaryRates->whereIn('id', $rateIds);
        $warnings = self::notes($calc['warnings'] ?? []);
        return compact('calc', 'problems', 'demo', 'warnings') + [
            'groups' => self::groups($problems, $entry), 'ready' => empty($problems),
            'days' => $base->filter(fn($line) => $line['amount_cents'] > 0)->pluck('earning_date')->unique()->count(),
            'rates' => $rates, 'manual' => $entry->source === 'manual',
            'netLabel' => $demo ? 'Simulated net pay' : ($entry->snapshot ? 'Net pay' : 'Estimated net pay'),
            'netAvailable' => empty($problems) && ($demo || $deductionsComplete),
            'deductionsComplete' => $deductionsComplete,
        ];
    }

    public static function notes(array $raw): array
    {
        $notes = [];
        foreach ($raw as $note) {
            $friendly = match (true) {
                str_starts_with($note, 'DEMO:') => 'Government deductions are excluded from this simulation. This is not take-home pay for a real payment.',
                str_contains($note, 'Unapproved overtime') => 'Extra hours have not been approved, so they are not included in pay.',
                str_contains(strtolower($note), 'legacy'), str_contains(strtolower($note), 'backfill') => 'Check the employee’s saved salary history against their agreement. Payroll uses the dated rate, not the older profile reference.',
                str_starts_with($note, 'Adjustment #') => 'An adjustment outside the employee’s employment dates was left out.',
                str_starts_with($note, 'Manual money') => 'Pay has been manually adjusted. Check the reason and supporting records before review.',
                default => 'There is an additional pay note to check in the technical details.',
            };
            $notes[] = $friendly;
        }
        return array_values(array_unique($notes));
    }
}
