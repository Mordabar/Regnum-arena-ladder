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

@once
@push('champion-boot')
    {{-- Visores 3D.
         El modulo solo se descarga si la pagina tiene algun visor, y el propio
         modulo se encarga de traer Three.js. Una pagina sin guerreros no paga
         ni un byte por esto. --}}
        <script>
            /* Las rutas de Three.js y del cargador de modelos, con su version.
               El modulo las lee de aqui en vez de escribirlas a mano: asi el
               cacheo de un ano no impide actualizar la libreria. */
            window.arenaChampionAssets = {
                three: "{{ asset('js/three.min.js') }}?v={{ @filemtime(public_path('js/three.min.js')) ?: '1' }}",
                loader: "{{ asset('js/three-gltf-loader.js') }}?v={{ @filemtime(public_path('js/three-gltf-loader.js')) ?: '1' }}"
            };
        </script>
        <script src="{{ asset('js/arena-champion.js') }}?v={{ @filemtime(public_path('js/arena-champion.js')) ?: '1' }}" defer></script>
        <script>
            /* Monta todos los [data-champion-viewer] de la pagina y los deja
               accesibles por id para que cada vista pueda cambiarlos en vivo
               (elegir otro personaje, cambiar de reino en el formulario...). */
            /* Los modelos que existen se listan aqui una sola vez: el visor no
               tiene que preguntarle al servidor por cada guerrero. */
            window.arenaChampionModels = @json(\App\Support\ChampionModels::available());
            window.arenaChampionViewers = {};
            document.addEventListener('DOMContentLoaded', function () {
                if (!window.ArenaChampion) { return; }
                document.querySelectorAll('[data-champion-viewer]').forEach(function (host) {
                    var canvas = host.querySelector('canvas');
                    if (!canvas) { return; }
                    window.arenaChampionViewers[host.dataset.championId] = window.ArenaChampion.mount(canvas, {
                        realm: host.dataset.championRealm,
                        subclass: host.dataset.championSubclass,
                        race: host.dataset.championRace,
                        gender: host.dataset.championGender,
                        parallax: host.dataset.championParallax !== '0'
                    });
                });
                document.dispatchEvent(new CustomEvent('arena:champions-ready'));
            });
        </script>
@endpush
@endonce
