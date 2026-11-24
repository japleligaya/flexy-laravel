<?php

namespace Tests\Unit;

use App\Services\Payroll\StatutoryRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatutoryRulesTest extends TestCase
{
    public static function sssFixtures(): array
    {
        // SSS Circular 2024-006 published rows (centavos), including band edges and MPF/EC split.
        return [[524999, 500000, 25000, 50000, 1000], [525000, 550000, 27500, 55000, 1000], [574999, 550000, 27500, 55000, 1000], [575000, 600000, 30000, 60000, 1000], [1474999, 1450000, 72500, 145000, 1000], [1475000, 1500000, 75000, 150000, 3000], [2025000, 2050000, 102500, 205000, 3000], [3474999, 3450000, 172500, 345000, 3000], [3475000, 3500000, 175000, 350000, 3000], [20000000, 3500000, 175000, 350000, 3000]];
    }

    #[DataProvider('sssFixtures')]
    public function test_official_sss_band_fixtures(int $pay, int $msc, int $ee, int $er, int $ec): void
    {
        $r = StatutoryRules::sss($pay);
        $this->assertSame($msc, $r['msc']);
        $this->assertSame($ee, $r['employee']);
        $this->assertSame($er, $r['employer']);
        $this->assertSame($ec, $r['ec']);
        $this->assertSame($ee, $r['employee_regular'] + $r['employee_mpf']);
        $this->assertSame($er, $r['employer_regular'] + $r['employer_mpf']);
    }

    public function test_philhealth_published_examples_and_conservation(): void
    {
        foreach ([[1000000, 50000], [3000000, 150000], [10000000, 500000], [20000000, 500000]] as [$salary,$premium]) {
            $r = StatutoryRules::philhealth($salary);
            $this->assertSame($premium, $r['total']);
            $this->assertSame($premium, $r['employee'] + $r['employer']);
        }
        $r = StatutoryRules::philhealth(1000020);
        $this->assertSame($r['total'], $r['employee'] + $r['employer']);
    }

    public function test_bir_published_bracket_boundaries_and_rounding(): void
    {
        foreach ([[2083300, 0], [3333300, 187500], [6666700, 854180], [16666700, 3354180], [66666700, 18354180], [3000000, 137505]] as [$taxable,$expected]) {
            $this->assertSame($expected, StatutoryRules::bir($taxable, 'monthly'));
        }
        foreach ([[1041700, 0], [1666700, 93750], [3333300, 427070], [8333300, 1677070], [33333300, 9177070]] as [$taxable,$expected]) {
            $this->assertSame($expected, StatutoryRules::bir($taxable, 'semi_monthly'));
        }
        $this->assertSame(1, StatutoryRules::round(1, 1, 2));
        $this->assertSame(0,StatutoryRules::round(1,1,3));
    }
}
