<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web_main.php',
        ],
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands()
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('ladder:tick')
            ->everyMinute()
            ->name('arena:tick')
            ->withoutOverlapping();

        $schedule->command('ladder:daily-maintenance')
            ->daily()
            ->name('arena:daily-maintenance')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('auth.discord'));
        $middleware->alias([
            'arena.admin' => \App\Http\Middleware\EnsureArenaAdminSession::class,
            'arena.maintenance' => \App\Http\Middleware\EnsureArenaMaintenanceFresh::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
