<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LadderCleanupMatchesCommand extends Command
{
    protected $signature = 'ladder:cleanup-matches';

    protected $description = 'Legacy alias of ladder:tick for backward compatibility.';

    public function handle(): int
    {
        return (int) $this->call('ladder:tick');
    }
}
