<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LadderProcessQueueCommand extends Command
{
    protected $signature = 'ladder:process-queue';

    protected $description = 'Legacy alias of ladder:tick for backward compatibility.';

    public function handle(): int
    {
        return (int) $this->call('ladder:tick');
    }
}
