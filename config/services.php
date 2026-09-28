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

        // Anuncios de actividad en un canal publico del servidor ("hay gente
        // en cola 1v1", "arranca un 2v2"...). Nada con nombres: para lo
        // personal estan los avisos. Sin canal, no se anuncia nada.
        'announcements' => [
            'channel_id' => env('DISCORD_ANNOUNCEMENTS_CHANNEL_ID'),
            // Como mucho un anuncio de cola abierta por modalidad cada tanto.
            'queue_every_minutes' => (int) env('DISCORD_ANNOUNCE_QUEUE_MINUTES', 15),
            // Como mucho un "arranca un combate" cada tanto, en total.
            'match_every_minutes' => (int) env('DISCORD_ANNOUNCE_MATCH_MINUTES', 10),
            // El resumen de actividad del cron.
            'pulse_every_minutes' => (int) env('DISCORD_ANNOUNCE_PULSE_MINUTES', 60),
        ],
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
