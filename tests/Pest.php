<?php

use App\Models\AppSetting;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

// Los ajustes se cachean en memoria dentro del proceso. La base de datos se
// revierte entre tests, pero esa cache no: hay que vaciarla a mano o un test
// arrastra la configuracion del anterior.
uses()->beforeEach(fn () => AppSetting::flushSettingsCache())->in('Feature');
