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

];
