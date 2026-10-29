<?php

namespace App\Console\Commands;

use App\Services\Admin\AiBudgets;
use Illuminate\Console\Command;

class ReconcileBudgetsCommand extends Command
{
    protected $signature = 'ai:reconcile-budgets';

    protected $description = 'Rebuild today\'s AI budget counters from reconciled ledger rows';

    public function handle(): int
    {
        $touched = AiBudgets::rebuildToday();
        $this->info("Rebuilt counters for {$touched} user(s).");

        return self::SUCCESS;
    }
}
