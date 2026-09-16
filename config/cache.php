<?php

return [
    /*
     * El almacen por defecto sale del entorno.
     *
     * Estaba escrito a fuego como 'file', y eso hacia que el `CACHE_STORE=array`
     * de phpunit.xml no sirviera de nada: la suite escribia en el MISMO cache de
     * fichero que la aplicacion, en storage/framework/cache/data. Dos efectos, y
     * los dos se vieron:
     *
     * - El `Cache::flush()` de cada test le quitaba el candado del emparejador a
     *   quien lo tuviera cogido, asi que el test del turno fallaba de vez en
     *   cuando sin tocar nada -1 de cada 8 ejecuciones limpias-.
     * - Y al reves: correr la suite en un servidor con la aplicacion viva le
     *   borraba el cache a produccion.
     */
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],

        // El de los tests: vive en memoria y muere con el proceso, asi que no
        // puede pisarle nada a nadie.
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'laravel_cache'),
];
