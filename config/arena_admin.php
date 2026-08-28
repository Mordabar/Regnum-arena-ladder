<?php

return [
    'path' => env('ARENA_ADMIN_PATH', 'lowly-control-room'),
    'max_attempts' => 5,
    'decay_seconds' => 300,
    'bootstrap_username' => env('ARENA_ADMIN_USERNAME', 'admin'),
    'bootstrap_display_name' => env('ARENA_ADMIN_DISPLAY_NAME', 'admin'),
    'bootstrap_password' => env('ARENA_ADMIN_PASSWORD'),
];
