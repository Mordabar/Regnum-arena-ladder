@extends('layouts.arena')

@section('title', 'Descargar la app - Regnum Arena Ladder')

@section('content')
@php
    $ios = fn (string $nombre) => asset('images/descargas/ios-' . $nombre . '.webp') . '?v=' . (@filemtime(public_path('images/descargas/ios-' . $nombre . '.webp')) ?: '1');

    $apk = public_path('apk/arena-ladder.apk');
    $apkUrl = asset('apk/arena-ladder.apk') . '?v=' . (@filemtime($apk) ?: '1');
    $apkKb = is_file($apk) ? max(1, (int) round(filesize($apk) / 1024)) : null;

    // Los pasos de iPhone, con las capturas de un iPhone de verdad.
    $pasosIos = [
        ['0-aviso', 'Abre regnumarenaladder.top en el iPhone. La propia web te avisa de que hay que añadirla al inicio.'],
        ['1-menu', 'Toca el menú del navegador (⋯) y elige «Compartir».'],
        ['2-compartir', 'Baja hasta «Agregar a Inicio».'],
        ['3-agregar', 'Deja activado «Abrir como app web» y toca «Agregar».'],
        ['4-icono', 'Ya tienes Arena Ladder en tu pantalla de inicio. Ábrela siempre desde ahí.'],
        ['5-app-campana', 'Dentro de la app, toca la campana roja de abajo a la derecha.'],
        ['6-permiso', 'El iPhone te pide permiso: toca «Permitir».'],
        ['7-activado', 'Listo: te llega el aviso de prueba y la campana se pone verde.'],
        ['8-bloqueo', 'Y así te llegan los avisos: aunque tengas el móvil bloqueado o estés en otra app.'],
    ];
@endphp

<div class="arena-descargas mx-auto max-w-5xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Descargar la app']]" class="mb-6" />

    <section class="arena-panel-strong arena-jugar-hero arena-animate-in">
        <p class="arena-kicker">App móvil</p>
        <h1 class="arena-jugar-titulo">Lleva la arena en el bolsillo</h1>
        <p class="arena-jugar-intro arena-body-text">
            Arena Ladder en tu móvil, a pantalla completa y con avisos: te enteras del cruce aunque
            estés dentro del juego. Es la misma web de siempre, así que cada novedad te llega sola,
            sin actualizar nada.
        </p>
        <div class="arena-descargas-saltos">
            <a href="#android" class="arena-btn"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.87 3.23a11.43 11.43 0 0 0-9.82 0L5.22 5.92a.38.38 0 0 0-.66.38L6.4 9.48A10.78 10.78 0 0 0 1 18h22a10.78 10.78 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 1.25-1.25A1.25 1.25 0 0 1 7 15.25zm10 0A1.25 1.25 0 1 1 18.25 14 1.25 1.25 0 0 1 17 15.25z"/></svg>Android</a>
            <a href="#iphone" class="arena-btn-ghost"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.2 1.8-3.26 1.88-3.31a4.05 4.05 0 0 0-3.18-1.72c-1.35-.14-2.64.8-3.33.8-.69 0-1.74-.78-2.86-.76a4.24 4.24 0 0 0-3.58 2.18c-1.53 2.65-.39 6.57 1.1 8.72.73 1.05 1.6 2.23 2.73 2.19 1.1-.04 1.51-.71 2.84-.71 1.32 0 1.7.71 2.86.69 1.18-.02 1.93-1.07 2.65-2.13a9.4 9.4 0 0 0 1.2-2.47 3.83 3.83 0 0 1-2.31-3.48zM14.2 6.13a3.8 3.8 0 0 0 .88-2.73 3.9 3.9 0 0 0-2.52 1.3 3.64 3.64 0 0 0-.9 2.64 3.22 3.22 0 0 0 2.54-1.21z"/></svg>iPhone</a>
        </div>
    </section>

    <div class="arena-descargas-plataformas" data-descargas>
        {{-- ── ANDROID ── --}}
        <section id="android" class="arena-jugar-paso arena-card" data-plataforma="android" aria-labelledby="descargaAndroid">
            <header class="arena-jugar-paso-head">
                <span class="arena-guia-num" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.87 3.23a11.43 11.43 0 0 0-9.82 0L5.22 5.92a.38.38 0 0 0-.66.38L6.4 9.48A10.78 10.78 0 0 0 1 18h22a10.78 10.78 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 1.25-1.25A1.25 1.25 0 0 1 7 15.25zm10 0A1.25 1.25 0 1 1 18.25 14 1.25 1.25 0 0 1 17 15.25z"/></svg></span>
                <h2 id="descargaAndroid">Android</h2>
            </header>

            <p class="arena-jugar-texto arena-body-text">
                Descarga la app, instálala y listo. Se abre directamente en tu lobby.
            </p>

            @if($apkKb)
                <a href="{{ $apkUrl }}" class="arena-btn arena-descargas-apk" download="arena-ladder.apk">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                    Descargar Arena Ladder ({{ $apkKb }} KB)
                </a>
            @endif

            <ol class="arena-descargas-pasos">
                <li><b>Descarga</b> el archivo con el botón de arriba.</li>
                <li><b>Ábrelo</b> desde la notificación de descarga. La primera vez Android te pedirá permitir
                    «instalar apps desconocidas» para tu navegador: es normal, la app no viene de la tienda.</li>
                <li><b>Instala</b> y abre Arena Ladder desde su icono.</li>
                <li>Dentro, <b>toca la campana</b> de abajo a la derecha y acepta el permiso: así te avisa de
                    los cruces aunque no la tengas abierta.</li>
            </ol>

            <p class="arena-jugar-nota is-tip"><span aria-hidden="true">💡</span><span>
                La app funciona con <b>Google Chrome</b>, que viene en casi todos los Android. Si no lo tienes,
                instálalo desde Play Store antes de abrirla.
            </span></p>
        </section>

        {{-- ── IPHONE ── --}}
        <section id="iphone" class="arena-jugar-paso arena-card" data-plataforma="ios" aria-labelledby="descargaIos">
            <header class="arena-jugar-paso-head">
                <span class="arena-guia-num" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.2 1.8-3.26 1.88-3.31a4.05 4.05 0 0 0-3.18-1.72c-1.35-.14-2.64.8-3.33.8-.69 0-1.74-.78-2.86-.76a4.24 4.24 0 0 0-3.58 2.18c-1.53 2.65-.39 6.57 1.1 8.72.73 1.05 1.6 2.23 2.73 2.19 1.1-.04 1.51-.71 2.84-.71 1.32 0 1.7.71 2.86.69 1.18-.02 1.93-1.07 2.65-2.13a9.4 9.4 0 0 0 1.2-2.47 3.83 3.83 0 0 1-2.31-3.48zM14.2 6.13a3.8 3.8 0 0 0 .88-2.73 3.9 3.9 0 0 0-2.52 1.3 3.64 3.64 0 0 0-.9 2.64 3.22 3.22 0 0 0 2.54-1.21z"/></svg></span>
                <h2 id="descargaIos">iPhone</h2>
            </header>

            <p class="arena-jugar-texto arena-body-text">
                Apple no deja instalar apps fuera de la App Store, pero el iPhone puede instalar la web
                como app en unos pocos toques: queda con su icono, a pantalla completa y con avisos.
            </p>

            <ol class="arena-descargas-ios">
                @foreach($pasosIos as $i => [$foto, $texto])
                    <li>
                        <figure>
                            <a href="{{ $ios($foto) }}" target="_blank" rel="noopener">
                                <img src="{{ $ios($foto) }}" alt="Paso {{ $i + 1 }}: {{ $texto }}" loading="lazy" decoding="async" width="540" height="1169">
                            </a>
                            <figcaption><span>{{ $i + 1 }}</span>{{ $texto }}</figcaption>
                        </figure>
                    </li>
                @endforeach
            </ol>

            <p class="arena-jugar-nota is-ojo"><span aria-hidden="true">⚠️</span><span>
                En iPhone los avisos solo llegan abriéndola desde el icono de la pantalla de inicio, no
                desde el navegador. Si el móvil está en silencio o en modo concentración, el aviso llega
                pero sin sonido.
            </span></p>
        </section>
    </div>

    <section class="arena-panel-strong arena-jugar-fin">
        <p class="arena-jugar-sub">¿En el PC? No hace falta instalar nada.</p>
        <p class="arena-jugar-intro arena-body-text" style="margin-inline:auto">
            Entra desde el navegador y deja la pestaña abierta: suena cuando hay novedades.
        </p>
        <div class="arena-jugar-fin-botones">
            <a href="{{ route('lobby') }}" class="arena-btn"><x-arena-icon name="swords" class="h-4 w-4 shrink-0" />Entrar a la arena</a>
            <a href="{{ route('como-jugar') }}" class="arena-btn-ghost"><x-arena-icon name="book" class="h-4 w-4 shrink-0" />Cómo jugar</a>
        </div>
    </section>
</div>

<script>
/* Quien entra desde un movil ve primero lo suyo: en un iPhone, los pasos de
   iPhone arriba; en Android, la descarga. */
(function () {
    var caja = document.querySelector('[data-descargas]');
    if (!caja) { return; }
    var ua = navigator.userAgent || '';
    var esIos = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var mio = caja.querySelector('[data-plataforma="' + (esIos ? 'ios' : /Android/i.test(ua) ? 'android' : '') + '"]');
    if (mio) {
        mio.classList.add('is-tuya');
        caja.insertBefore(mio, caja.firstChild);
    }
})();
</script>
@endsection
