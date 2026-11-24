<?php

namespace App\Services\Payroll;

/** Presentation of a locked snapshot; never reads mutable salary or attendance. */
final class PayslipDocument
{
    public static function lines(array $snapshot): array
    {
        $rows = [];
        foreach ($snapshot['calculation']['lines'] as $line) {
            $key = $line['type'] === 'base' && isset($line['salary_rate_id']) ? 'base:'.$line['salary_rate_id'].':'.$line['basis'] : null;
            if ($key === null) {
                if (isset($line['component'])) {
                    $line['basis'] = $snapshot['calculation']['statutory'][$line['component']]['status'] ?? $line['basis'];
                }
                $rows[] = $line;

                continue;
            }
            if (! isset($rows[$key])) {
                $rows[$key] = $line + ['day_count' => 0, 'date_start' => $line['earning_date']];
            } else {
                $rows[$key]['amount_cents'] += $line['amount_cents'];
            }
            $rows[$key]['day_count']++;
            $rows[$key]['earning_date'] = $rows[$key]['date_start'].' to '.$line['earning_date'];
            $rows[$key]['label'] = 'Base pay · '.$rows[$key]['day_count'].' employment day(s)';
        }

        return array_values($rows);
    }
}
