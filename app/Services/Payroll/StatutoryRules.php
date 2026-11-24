<?php

namespace App\Services\Payroll;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/** Pure published-table functions. Eligibility and effective dates are checked by the engine. */
final class StatutoryRules
{
    public static function round(int $amount, int $numerator, int $denominator): int
    {
        return BigRational::of($amount)->multipliedBy($numerator)->dividedBy($denominator)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function sss(int $compensation): array
    {
        // 500-peso MSC bands, e.g. 5,249.99 => 5,000; 5,250 => 5,500.
        $msc = max(500000, min(3500000, intdiv($compensation + 25000, 50000) * 50000));
        $regular = min(2000000, $msc);
        $mpf = max(0, $msc - $regular);

        return ['employee' => self::round($msc, 5, 100), 'employer' => self::round($msc, 10, 100),
            'ec' => $msc < 1500000 ? 1000 : 3000, 'msc' => $msc,
            'employee_regular' => self::round($regular, 5, 100), 'employee_mpf' => self::round($mpf, 5, 100),
            'employer_regular' => self::round($regular, 10, 100), 'employer_mpf' => self::round($mpf, 10, 100)];
    }

    public static function philhealth(int $monthlyBasic): array
    {
        $total = self::round(max(1000000, min(10000000, $monthlyBasic)), 5, 100);
        $employee = self::round($total, 1, 2);

        return ['employee' => $employee, 'employer' => $total - $employee, 'total' => $total];
    }

    public static function bir(int $taxable, string $frequency): int
    {
        $rows = match ($frequency) {
            'monthly' => [[0, 0, 0], [2083300, 0, 15], [3333300, 187500, 20], [6666700, 854180, 25], [16666700, 3354180, 30], [66666700, 18354180, 35]],
            'semi_monthly' => [[0, 0, 0], [1041700, 0, 15], [1666700, 93750, 20], [3333300, 427070, 25], [8333300, 1677070, 30], [33333300, 9177070, 35]],
            default => throw new \InvalidArgumentException('Unsupported BIR pay frequency'),
        };
        $row = $rows[0];
        foreach ($rows as $r) {
            if ($taxable >= $r[0]) {
                $row = $r;
            }
        }

        return $row[1] + self::round(max(0, $taxable - $row[0]), $row[2], 100);
    }
}
