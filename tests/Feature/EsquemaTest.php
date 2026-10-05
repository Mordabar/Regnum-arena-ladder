<?php

use App\Support\Esquema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

it('un no caduca en segundos: en cuanto existe, se ve, sin olvidar nada a mano', function () {
    Esquema::olvidar();

    expect(Esquema::tabla('tabla_que_no_existe'))->toBeFalse();

    DB::statement('create table tabla_que_no_existe (id integer)');

    // Dentro de la ventana el no se recuerda (no se repite la consulta)...
    expect(Esquema::tabla('tabla_que_no_existe'))->toBeFalse();

    // ...pero pasada, un worker que lleva horas vivo ya la ve.
    Carbon::setTestNow(now()->addMinute());

    expect(Esquema::tabla('tabla_que_no_existe'))->toBeTrue();

    Carbon::setTestNow();
});

it('olvidar no vacia el resto de la cache', function () {
    Cache::put('candado-que-no-se-toca', 'x', 60);

    Esquema::tabla('arena_seasons');
    Esquema::olvidar();

    expect(Cache::get('candado-que-no-se-toca'))->toBe('x')
        ->and(Cache::get('esquema:t:arena_seasons'))->toBeNull();
});
