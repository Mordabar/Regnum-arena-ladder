<?php

use Monolog\Handler\NullHandler;

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            // Diario por defecto: con un solo fichero, laravel.log crecia sin
            // limite en un hosting con el disco contado.
            'channels' => explode(',', (string) env('LOG_STACK', 'daily')),
        ],

        // Un fichero por dia (laravel-AAAA-MM-DD.log); se guardan los ultimos
        // LOG_DAILY_DAYS y los viejos se borran solos.
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        // Para los tests (phpunit.xml): sin esto Laravel no encontraba el
        // canal y caia al registro de emergencia, que escribe igualmente en
        // storage/logs.
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],
    ],
];
