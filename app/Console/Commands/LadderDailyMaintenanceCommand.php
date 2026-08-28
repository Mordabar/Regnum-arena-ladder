<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Queue;
use Illuminate\Console\Command;

class LadderDailyMaintenanceCommand extends Command
{
    protected $signature = 'ladder:daily-maintenance';

    protected $description = 'Daily maintenance: reset expired penalty strikes, clean old cancelled queues.';

    public function handle(): int
    {
        // Reset penalty strikes for players whose lock already expired (grace period: 7 days)
        $resetStrikes = Player::query()
            ->where('penalty_strikes', '>', 0)
            ->whereNotNull('queue_locked_until')
            ->where('queue_locked_until', '<', now()->subDays(7))
            ->update([
                'penalty_strikes' => 0,
                'queue_lock_reason' => null,
                'last_penalty_type' => null,
            ]);

        // Purge old cancelled queue entries (older than 30 days)
        $purgedQueues = Queue::query()
            ->where('status', 'cancelled')
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Reset penalty strikes: {$resetStrikes}");
        $this->info("Purged old cancelled queues: {$purgedQueues}");
        \Illuminate\Support\Facades\Log::info("Cron ladder:daily-maintenance ran. Reset: {$resetStrikes}, Purged: {$purgedQueues}");

        return self::SUCCESS;
    }
}
