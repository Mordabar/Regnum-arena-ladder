<?php

return [
    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . '/auth/discord/callback',
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'guild_id' => env('DISCORD_GUILD_ID'),
        'alerts_channel_id' => env('DISCORD_ALERTS_CHANNEL_ID'),
        'admin_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_DISCORD_IDS', ''))))),
    ],
];
