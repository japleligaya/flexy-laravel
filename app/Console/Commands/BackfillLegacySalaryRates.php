<?php

namespace App\Console\Commands;

use App\Services\Payroll\LegacySalaryBackfill;
use Illuminate\Console\Command;

class BackfillLegacySalaryRates extends Command
{
    protected $signature = 'payroll:backfill-legacy-rates';

    protected $description = 'Idempotently backfill legacy-only salary using recorded hire dates; missing dates require review';

    public function handle(LegacySalaryBackfill $backfill): int
    {
        foreach ($backfill->run() as $label => $count) {
            $this->line($label.': '.$count);
        }

        return self::SUCCESS;
    }
}
