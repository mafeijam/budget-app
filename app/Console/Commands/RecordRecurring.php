<?php

namespace App\Console\Commands;

use App\Support\RecurringPayments;
use Illuminate\Console\Command;

class RecordRecurring extends Command
{
    protected $signature = 'recurring:record';

    protected $description = 'Write every recurring transaction due through today as a pending row';

    public function handle(): int
    {
        $result = RecurringPayments::recordDue(today());

        $this->info("{$result['recorded']} recorded.");

        foreach ($result['refusals'] as $refusal) {
            $this->warn($refusal);
        }

        return $result['refusals'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
