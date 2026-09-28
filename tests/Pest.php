<?php

use App\Models\AppSetting;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

// Los ajustes se cachean en memoria dentro del proceso. La base de datos se
// revierte entre tests, pero esa cache no: hay que vaciarla a mano o un test
// arrastra la configuracion del anterior.
// La base de datos se revierte entre tests, pero la cache no: sin vaciarla, el
// ladder que dejo cacheado un test aparece en el siguiente y el fallo salta en
// un test que no tiene nada que ver con el que lo provoco.
uses()->beforeEach(function () {
    AppSetting::flushSettingsCache();
    \Illuminate\Support\Facades\Cache::flush();

    // Ningun test escribe en el disco de verdad: las capturas de los reportes
    // y los respaldos del editor de zonas iban a storage/app y se quedaban
    // ahi despues de cada pasada. Con los discos falsos todo va a
    // storage/framework/testing, que se vacia solo en cada test.
    \Illuminate\Support\Facades\Storage::fake(\App\Models\MatchReport::EVIDENCE_DISK);
    \Illuminate\Support\Facades\Storage::fake('local');

    // Ninguna peticion HTTP de verdad (Discord, push...): el test que la
    // necesite tiene que simularla con Http::fake.
    \Illuminate\Support\Facades\Http::preventStrayRequests();
})->in('Feature');

/**
 * Una sesion de admin con una cuenta de verdad detras.
 *
 * La sesion del panel se revalida en cada peticion contra la tabla de cuentas:
 * una sesion que dice "soy admin" sin una cuenta activa que la respalde ya no
 * vale. Los tests usaban un account_id inventado.
 */
function sesionDeAdmin(): array
{
    $cuenta = \App\Models\AdminAccount::query()->updateOrCreate(
        ['username' => 'admin'],
        ['password_hash' => password_hash('clave-de-tests', PASSWORD_BCRYPT), 'display_name' => 'admin', 'is_active' => true]
    );

    return [
        'arena_admin.authenticated' => true,
        'arena_admin.account_id' => $cuenta->id,
        'arena_admin.username' => $cuenta->username,
        'arena_admin.display_name' => 'admin',
    ];
}

/**
 * El layout del sitio con lo que se saco de el: los estilos (css/arena.css) y
 * los scripts globales (partials/arena-scripts). Los tests que buscan una
 * regla o un trozo de script no tienen que saber en cual de los tres vive.
 */
function plantillaDelSitio(): string
{
    return file_get_contents(resource_path('views/layouts/arena.blade.php'))
        . "\n" . file_get_contents(resource_path('views/partials/arena-scripts.blade.php'))
        . "\n" . file_get_contents(public_path('css/arena.css'));
}
