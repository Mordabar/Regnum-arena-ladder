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
})->in('Feature');
