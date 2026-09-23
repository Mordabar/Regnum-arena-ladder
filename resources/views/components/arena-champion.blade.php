@props([
    'realm' => 'ignis',
    'subclass' => 'knight',
    'race' => null,
    'gender' => 'male',
    'height' => '420px',
    'id' => null,
    'parallax' => true,
    'defer' => false,
    // Retratos pequenos: la camara se ciñe al guerrero en vez de dejarle
    // aire alrededor, para que llene el marco.
    'tight' => false,
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
     @if($defer) data-champion-defer="1" @endif
     @if($tight) data-champion-tight="1" @endif
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
            /* El hueco de cada visor, por id. Hace falta para saber si el que
               esta montado es ESTE nodo o uno anterior con el mismo id. */
            window.arenaChampionHosts = {};
            /* Los que esperan a estar a la vista, con su observador. */
            window.arenaChampionEsperas = [];

            /* Suelta los visores cuyo hueco ya no esta en la pagina.
               Un navegador aguanta un punado de contextos WebGL y luego empieza
               a cerrar los mas viejos: si el panel se repinta y los visores
               antiguos siguen vivos, a la tercera vez las figuras desaparecen
               sin decir por que. */
            window.arenaDisposeOrphanChampions = function () {
                Object.keys(window.arenaChampionViewers).forEach(function (id) {
                    var viewer = window.arenaChampionViewers[id];
                    /* Se mira EL NODO que monto este visor, no cualquiera que
                       lleve su id. Varios huecos se llaman igual a proposito
                       -hub-stage, premade-leader- y al repintar el panel llega
                       otro nodo con el mismo nombre: buscandolo por id se
                       encontraba el nuevo, se daba el viejo por vivo y su
                       contexto WebGL quedaba suelto sin que nadie lo cerrara. */
                    var host = window.arenaChampionHosts[id];
                    if (host && host.isConnected) { return; }

                    if (viewer && typeof viewer.dispose === 'function') {
                        try { viewer.dispose(); } catch (error) { console.error(error); }
                    }
                    delete window.arenaChampionViewers[id];
                    delete window.arenaChampionHosts[id];
                });

                /* Y los que se quedaron esperando a entrar en pantalla y nunca
                   llegaron a montarse: no estan en el mapa de visores, asi que
                   el barrido de arriba no los ve. Su observador mantendria vivo
                   un nodo ya desconectado, uno por repintado y sin techo. */
                window.arenaChampionEsperas = window.arenaChampionEsperas.filter(function (espera) {
                    if (espera.host.isConnected) { return true; }
                    try { espera.observer.disconnect(); } catch (error) {}
                    return false;
                });
            };

            /* Monta un visor concreto, ya sin condiciones. */
            window.arenaMontarVisor = function (host) {
                if (!window.ArenaChampion || host.dataset.championMounted === '1') { return; }

                var canvas = host.querySelector('canvas');
                if (!canvas) { return; }

                host.dataset.championMounted = '1';
                window.arenaChampionHosts[host.dataset.championId] = host;
                window.arenaChampionViewers[host.dataset.championId] = window.ArenaChampion.mount(canvas, {
                    realm: host.dataset.championRealm,
                    subclass: host.dataset.championSubclass,
                    race: host.dataset.championRace,
                    gender: host.dataset.championGender,
                    parallax: host.dataset.championParallax !== '0',
                    tight: host.dataset.championTight === '1'
                });
            };

            /* Los diferidos se montan de uno en uno.
               No basta con esperar a que se vean: el podio es una fila de tres
               cajones, tambien en movil -asi esta puesto el CSS a proposito-,
               asi que los tres entran en pantalla a la vez y las tres descargas
               salian juntas igualmente. Encolarlos reparte el gasto: cada uno
               arranca cuando el navegador tiene un hueco libre. */
            window.arenaChampionCola = [];
            window.arenaChampionColaCorriendo = false;

            window.arenaEncolarVisor = function (host) {
                window.arenaChampionCola.push(host);
                if (window.arenaChampionColaCorriendo) { return; }

                window.arenaChampionColaCorriendo = true;

                var siguiente = function () {
                    var host = window.arenaChampionCola.shift();

                    if (!host) {
                        window.arenaChampionColaCorriendo = false;
                        return;
                    }

                    if (host.isConnected) { window.arenaMontarVisor(host); }

                    var espera = window.requestIdleCallback || function (fn) { return setTimeout(fn, 120); };
                    espera(siguiente, { timeout: 600 });
                };

                siguiente();
            };

            /* Monta los visores que todavia no lo estan. Se puede llamar tantas
               veces como haga falta: el hueco ya montado lleva su marca. */
            window.arenaMountChampions = function (root) {
                if (!window.ArenaChampion) { return; }

                window.arenaDisposeOrphanChampions();

                (root || document).querySelectorAll('[data-champion-viewer]').forEach(function (host) {
                    if (host.dataset.championMounted === '1') { return; }

                    /* Los marcados como diferidos esperan a estar a la vista y
                       luego pasan por la cola. Montar cuesta un contexto WebGL
                       y un modelo de varios cientos de kilobytes, y un movil
                       aguanta pocos a la vez: lo que se busca es que no se
                       paguen todos de golpe en la primera pantalla. */
                    if (host.dataset.championDefer === '1' && 'IntersectionObserver' in window) {
                        if (host.dataset.championWatched === '1') { return; }
                        host.dataset.championWatched = '1';

                        var observador = new IntersectionObserver(function (entries) {
                            if (!entries[0].isIntersecting) { return; }
                            observador.disconnect();
                            delete host.dataset.championDefer;
                            window.arenaEncolarVisor(host);
                        }, { rootMargin: '200px' });

                        observador.observe(host);
                        window.arenaChampionEsperas.push({ host: host, observer: observador });
                        return;
                    }

                    window.arenaMontarVisor(host);
                });

                document.dispatchEvent(new CustomEvent('arena:champions-ready'));
            };

            // El modulo llega con defer, asi que puede montarse despues de que
            // el documento este listo; por eso se intenta en los dos momentos.
            document.addEventListener('DOMContentLoaded', function () { window.arenaMountChampions(document); });
            window.addEventListener('load', function () { window.arenaMountChampions(document); });
            document.addEventListener('arena:dom-updated', function (event) {
                window.arenaMountChampions((event.detail && event.detail.root) || document);
            });
        </script>
@endpush
@endonce
