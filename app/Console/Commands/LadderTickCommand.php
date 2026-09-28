<?php

namespace App\Console\Commands;

use App\Services\ArenaMaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LadderTickCommand extends Command
{
    protected $signature = 'ladder:tick';

    protected $description = 'Run the unified arena maintenance tick.';

    public function handle(ArenaMaintenanceService $maintenanceService): int
    {
        $result = $maintenanceService->runTick();

        if ($result['skipped']) {
            $message = 'Maintenance tick skipped because another cycle ran recently.';
            $this->info($message);
            Log::info('Cron ladder:tick skipped.', $result);

            return self::SUCCESS;
        }

        $this->info("Cleaned stale queues: {$result['stale_queues']}");
        $this->info("Expired matches: {$result['expired_matches']}");
        $this->info("Expired hunts: {$result['expired_hunts']}");
        $this->info("Expired report confirmations: {$result['expired_report_confirmations']}");
        $this->info("Created matches: {$result['created_matches']}");
        Log::info('Cron ladder:tick ran.', $result);

        return self::SUCCESS;
    }
}
