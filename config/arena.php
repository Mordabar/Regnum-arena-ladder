<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Espera antes de repartir
    |--------------------------------------------------------------------------
    |
    | Segundos que una fila de cola pasa madurando antes de entrar al reparto.
    | Sin esto, dos personas que coinciden por casualidad se cruzan al instante
    | aunque un rival mucho mejor entre dos segundos despues. El admin lo puede
    | cambiar desde el panel; esto es solo el valor de partida.
    |
    */

    'matchmaking_hold_seconds' => (int) env('ARENA_MATCHMAKING_HOLD_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Descanso entre revanchas
    |--------------------------------------------------------------------------
    |
    | Minutos durante los que repetir rival sale muy caro. No es una prohibicion:
    | si de verdad no hay nadie mas, se repite igual antes que dejar a los dos
    | en cola.
    |
    | Se llama igual en los tres sitios -aqui, el env y el ajuste del panel- a
    | proposito: buscar "rematch_rest_minutes" tiene que encontrarlos todos.
    |
    */

    'rematch_rest_minutes' => (int) env('ARENA_REMATCH_REST_MINUTES', 2),

    /*
    |--------------------------------------------------------------------------
    | Zona horaria de las temporadas
    |--------------------------------------------------------------------------
    |
    | Las fechas de inicio y fin de una temporada se escriben y se leen en esta
    | zona, no en la del servidor (UTC): "termina el 29 de noviembre a las 23:59"
    | tiene que significar lo mismo para quien lo configura y para quien mira la
    | barra. En la base de datos se guardan en UTC, como todo lo demas.
    |
    */

    'season_timezone' => env('ARENA_SEASON_TIMEZONE', 'America/Bogota'),

    /*
    |--------------------------------------------------------------------------
    | Grabar las frases sin traducir
    |--------------------------------------------------------------------------
    |
    | Solo para desarrollo: con ARENA_I18N_RECORD=true cada frase que se pide a
    | un catalogo y no esta se anota en storage/app/i18n-pendientes.json, y de
    | ahi sale la lista de lo que falta traducir. En produccion, apagado: no
    | escribe nada.
    |
    */

    // Elegir el idioma del navegador la primera vez que se entra.
    'i18n_detect_browser' => (bool) env('ARENA_I18N_DETECT_BROWSER', true),

    'i18n_record' => (bool) env('ARENA_I18N_RECORD', false),

    'i18n_record_file' => storage_path('app/i18n-pendientes.json'),

];
