<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('arena:about', function () {
    $this->comment('Regnum Arena Ladder console routes loaded.');
})->purpose('Quick sanity check for console route loading.');
