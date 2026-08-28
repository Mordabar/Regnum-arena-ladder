<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LadderExpirePendingMatchesCommand extends Command
{
    protected $signature = 'ladder:expire-pending-matches';

    protected $description = 'Legacy alias of ladder:tick for backward compatibility.';

    public function handle(): int
    {
        return (int) $this->call('ladder:tick');
    }
}
