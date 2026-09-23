{{-- La navegacion del movil: una barra fija abajo, al alcance del pulgar,
     como en cualquier app. Sustituye a la hamburguesa (que queda solo para el
     panel de admin). Orden: Lobby, Ladder, Matches, Fama y Guia; la guia abre
     una hoja pequeña con sus dos paginas para no ocupar mas ancho. --}}
@php
    $tabs = [
        ['ruta' => 'lobby', 'activa' => ['lobby', 'player.*'], 'icono' => 'swords', 'texto' => 'Lobby'],
        ['ruta' => 'ladder.index', 'activa' => ['ladder.*'], 'icono' => 'ladder', 'texto' => 'Ladder'],
        ['ruta' => 'matches.index', 'activa' => ['matches.*'], 'icono' => 'matches', 'texto' => 'Matches'],
        ['ruta' => 'hall-of-fame', 'activa' => ['hall-of-fame'], 'icono' => 'star', 'texto' => 'Fama'],
    ];
    $enGuia = request()->routeIs('como-jugar', 'guia', 'descargas');
@endphp

<nav class="arena-tabbar" aria-label="Navegación principal">
    @foreach($tabs as $tab)
        @php $activa = request()->routeIs(...$tab['activa']); @endphp
        <a href="{{ route($tab['ruta']) }}" class="arena-tabbar-item {{ $activa ? 'is-activa' : '' }}" @if($activa) aria-current="page" @endif>
            <span class="arena-tabbar-icono"><x-arena-icon :name="$tab['icono']" class="h-5 w-5" /></span>
            <span>{{ $tab['texto'] }}</span>
        </a>
    @endforeach

    <button type="button" class="arena-tabbar-item {{ $enGuia ? 'is-activa' : '' }}" data-tabbar-guia aria-expanded="false" aria-controls="arenaTabbarHoja">
        <span class="arena-tabbar-icono"><x-arena-icon name="book" class="h-5 w-5" /></span>
        <span>Guía</span>
    </button>
</nav>

<div class="arena-tabbar-hoja" id="arenaTabbarHoja" hidden>
    <div class="arena-tabbar-hoja-fondo" data-tabbar-cerrar></div>
    <div class="arena-tabbar-hoja-panel" role="dialog" aria-label="Guía">
        <a href="{{ route('como-jugar') }}" class="{{ request()->routeIs('como-jugar') ? 'is-activa' : '' }}">
            <x-arena-icon name="book" class="h-5 w-5" />
            <span><b>Cómo jugar</b><small>Paso a paso, con capturas</small></span>
        </a>
        <a href="{{ route('guia') }}" class="{{ request()->routeIs('guia') ? 'is-activa' : '' }}">
            <x-arena-icon name="scale" class="h-5 w-5" />
            <span><b>Cómo funciona</b><small>Reglas y puntuación</small></span>
        </a>
        <a href="{{ route('descargas') }}" class="{{ request()->routeIs('descargas') ? 'is-activa' : '' }}">
            <x-arena-icon name="download" class="h-5 w-5" />
            <span><b>Descargar la app</b><small>Android e iPhone</small></span>
        </a>
        <div class="arena-tabbar-hoja-pie">
            @auth
                <span>{{ auth()->user()->discord_username }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="arena-btn-ghost px-4 py-2 text-sm"><x-arena-icon name="logout" class="h-4 w-4 shrink-0" />Salir</button>
                </form>
            @else
                <a href="{{ route('auth.discord') }}" class="arena-btn-secondary w-full justify-center"><x-arena-icon name="login" class="h-4 w-4 shrink-0" />Entrar con Discord</a>
            @endauth
        </div>
    </div>
</div>

<script>
(function () {
    var boton = document.querySelector('[data-tabbar-guia]');
    var hoja = document.getElementById('arenaTabbarHoja');
    if (!boton || !hoja) { return; }

    function abrir(si) {
        hoja.hidden = !si;
        boton.setAttribute('aria-expanded', si ? 'true' : 'false');
    }

    boton.addEventListener('click', function () { abrir(hoja.hidden); });
    hoja.addEventListener('click', function (e) {
        if (e.target.closest('[data-tabbar-cerrar]')) { abrir(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { abrir(false); }
    });
})();
</script>
