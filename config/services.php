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

    /*
     * Los avisos del navegador (Web Push).
     *
     * Las dos claves se sacan con `php artisan arena:push-keys` y se pegan en
     * el `.env`. Sin ellas el sitio funciona igual: simplemente no manda
     * avisos con la pestaña cerrada, y el codigo lo comprueba antes de
     * intentarlo en vez de reventar.
     *
     * Ojo: cambiar la clave publica invalida TODAS las suscripciones que haya
     * guardadas. Se generan una vez y no se tocan.
     */
    'webpush' => [
        // Solo en MOVILES, como ultimo recurso: con el movil en otra app el
        // navegador se duerme y el sonido no puede salir. En el escritorio no
        // hay push: sonido y aviso interno. VAPID_ENABLED=false lo apaga.
        'enabled' => (bool) env('VAPID_ENABLED', true),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // A quien escribir si los avisos dan problemas. Los servicios de push
        // exigen un contacto; un `mailto:` es lo habitual.
        'subject' => env('VAPID_SUBJECT'),
        // SOLO para probar en local contra un servicio de push falso. En
        // produccion no se define: la lista de servicios validos es fija
        // (Google, Mozilla, Apple, Microsoft) y no admite nada mas.
        'hosts_extra' => env('VAPID_HOSTS_EXTRA'),
    ],
];
