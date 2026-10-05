<?php

use App\Support\Esquema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('un si se recuerda: la segunda pregunta no toca la base de datos', function () {
    Esquema::olvidar();

    Esquema::columna('arena_seasons', 'auto_close');
    Esquema::tabla('arena_seasons');

    $consultas = 0;
    DB::listen(function () use (&$consultas) { $consultas++; });

    expect(Esquema::columna('arena_seasons', 'auto_close'))->toBeTrue()
        ->and(Esquema::tabla('arena_seasons'))->toBeTrue();

    expect($consultas)->toBe(0);
});

it('un no no se queda pegado: en cuanto existe, se ve', function () {
    Esquema::olvidar();

    expect(Esquema::tabla('tabla_que_no_existe'))->toBeFalse();

    // El "no" no se guarda: tras olvidar la memoria de la peticion se vuelve a mirar.
    Esquema::olvidar();
    DB::statement('create table tabla_que_no_existe (id integer)');

    expect(Esquema::tabla('tabla_que_no_existe'))->toBeTrue();
});
