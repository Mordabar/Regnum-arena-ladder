@props([
    'realm' => 'ignis',
    'subclass' => 'knight',
    'race' => null,
    'gender' => 'male',
    'height' => '420px',
    'id' => null,
    'parallax' => true,
])
@php
    // Un id estable permite que la vista que lo monta se refiera a este visor
    // sin depender del orden en que aparezcan en la pagina.
    $viewerId = $id ?: 'champion-' . uniqid();
@endphp

{{-- Visor 3D del guerrero.
     El canvas queda vacio y sin coste hasta que el modulo lo monta: si no hay
     WebGL o el navegador no ejecuta JavaScript, lo que se ve es el emblema del
     reino, no un hueco negro. --}}
<div {{ $attributes->merge(['class' => 'arena-champion']) }}
     data-champion-viewer
     data-champion-id="{{ $viewerId }}"
     data-champion-realm="{{ $realm }}"
     data-champion-subclass="{{ $subclass }}"
     data-champion-race="{{ $race ?: \App\Models\Player::defaultRace($realm) }}"
     data-champion-gender="{{ $gender ?: 'male' }}"
     data-champion-parallax="{{ $parallax ? '1' : '0' }}"
     style="height: {{ $height }}">

    <canvas class="arena-champion-canvas" aria-hidden="true"></canvas>

    {{-- El emblema es lo que se ve antes del 3D y tambien si no hay 3D. El
         aviso, en cambio, solo aparece cuando de verdad no se puede dibujar:
         mientras se descarga la libreria decia algo que no era cierto. --}}
    <div class="arena-champion-fallback" data-champion-fallback data-champion-state="idle">
        <span class="arena-champion-glyph" data-champion-glyph aria-hidden="true">
            {{ ['ignis' => '◆', 'alsius' => '✹', 'syrtis' => '❀'][$realm] ?? '◆' }}
        </span>
        <p class="arena-champion-fallback-note">Vista 3D no disponible en este navegador.</p>
    </div>

    {{ $slot }}
</div>
