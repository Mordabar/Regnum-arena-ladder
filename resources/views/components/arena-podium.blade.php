{{-- `compacto`: lo mismo, mas bajo. Para el ladder, donde lo que se viene a
     ver es el ranking y el podio de 611px empujaba la tabla fuera de la
     pantalla.

     Las figuras SIGUEN estando: quitarlas dejaba la seccion en tres cajitas de
     texto y el sitio pierde justo lo que lo distingue. Lo que cambia es el
     tamaño -el escenario se queda en un tercio- y el escalonado, que ahi no
     hace falta. --}}
@props(['podio', 'premios', 'compacto' => false])
@php
    use App\Models\Player as PlayerModel;
    use App\Services\SeasonPrizeService;

    // El orden clasico: el segundo a la izquierda, el ganador en medio y mas
    // alto, el tercero a la derecha. Leer un podio de 1-2-3 de izquierda a
    // derecha obliga a buscar quien gano; asi se ve solo.
    $enOrden = collect(SeasonPrizeService::ORDEN_DEL_PODIO)
        ->map(fn (int $puesto) => $podio->firstWhere('puesto', $puesto))
        ->filter()
        ->values();

    $medallas = [1 => '🥇', 2 => '🥈', 3 => '🥉'];

    // La unidad del cajon sale de la moneda configurada, no escrita a mano.
    // Con "lingotes" fijo, cambiar el premio a otra cosa en los ajustes dejaba
    // el titulo diciendo una moneda y los tres cajones otra.
    $unidad = trim((string) explode(' ', trim($premios->moneda()))[0]);
    $unidadPlural = $unidad !== '' ? $unidad : 'premios';
    $unidadSingular = mb_strlen($unidadPlural) > 1 && mb_substr($unidadPlural, -1) === 's'
        ? mb_substr($unidadPlural, 0, -1)
        : $unidadPlural;

    // La abreviatura del cajon en movil: "10 L" en vez de "10 lingotes". Con la
    // palabra entera no cabia y habia que esconderla, y entonces el cajon solo
    // decia un numero suelto que no significaba nada.
    $unidadCorta = mb_strtoupper(mb_substr($unidadPlural, 0, 1));
@endphp

{{-- El podio de la temporada.

     Un ladder sin premios a la vista es una tabla; con ellos es una temporada.
     Esto es lo primero que ve alguien que entra sin cuenta, asi que dice las
     dos cosas que importan de un vistazo: que se reparte y quien lo lleva. --}}
<section class="arena-podium @if($compacto) is-compacto @endif" aria-labelledby="arenaPodiumTitle">
    <header class="arena-podium-head">
        <x-arena-premio-cabecera :premios="$premios" />

        {{-- El calendario de la temporada: cuanto lleva y cuanto queda. Va en
             la cabecera del podio porque es la otra mitad de lo que se viene a
             mirar: que se reparte y hasta cuando se puede ganar. --}}
        <x-arena-season-bar :season="\App\Models\ArenaSeason::current()" />
    </header>

    <div class="arena-podium-stage">
        @foreach($enOrden as $puesto)
            @php
                $player = $puesto['player'];
                $esPrimero = $puesto['puesto'] === 1;
            @endphp

            <div class="arena-podium-slot is-{{ $puesto['puesto'] }}">
                <div class="arena-podium-figure">
                    @if($player)
                        {{-- El campeon de verdad, no un icono: la portada de un
                             juego enseña el juego. --}}
                        <a href="{{ route('ladder.show', $player) }}" class="arena-podium-champion" aria-label="{{ __('Ver la ficha de :name', ['name' => $player->cleanName()]) }}">
                            <x-arena-champion
                                :id="'podium-' . $puesto['puesto']"
                                :realm="$player->realm"
                                :subclass="$player->subclass"
                                :race="$player->race"
                                :gender="$player->gender"
                                :parallax="false"
                                {{-- El ganador se monta ya; los otros dos van
                                     por la cola, de uno en uno.
                                     Los tres cajones estan en la misma fila
                                     tambien en movil -el CSS lo mantiene a
                                     proposito-, asi que entran en pantalla a la
                                     vez: esperar a verlos no reparte nada. Lo
                                     que reparte es encolarlos, porque tres
                                     contextos WebGL y un mega de modelos de
                                     golpe es lo que se atraganta en un movil. --}}
                                :defer="!$esPrimero"
                                height="100%"
                                class="arena-podium-viewer" />
                        </a>
                    @else
                        {{-- Sin nadie todavia: el hueco se enseña vacio y se
                             dice, en vez de esconder el puesto. Un podio con
                             tres cajones cuenta lo que se reparte; uno con dos
                             parece roto. --}}
                        <div class="arena-podium-empty">
                            <span aria-hidden="true">?</span>
                            <small>Libre</small>
                        </div>
                    @endif
                </div>

                {{-- El cajon. El nombre va DENTRO, como grabado: fuera, con
                     tres cajones de alturas distintas, los nombres quedaban a
                     tres alturas distintas y el conjunto se leia torcido. --}}
                <div class="arena-podium-block" data-place="{{ $puesto['puesto'] }}">
                    <span class="arena-podium-medal" aria-hidden="true">{{ $medallas[$puesto['puesto']] ?? '🏅' }}</span>

                    {{-- El premio con su gema. La palabra entera en pantalla
                         grande y la inicial en movil: "10 L" sigue diciendo de
                         que va, y el cristal al lado lo remata. Un numero
                         suelto, que es lo que habia, no decia nada. --}}
                    <span class="arena-podium-prize">
                        {{-- La cifra y la unidad van juntas en su propia linea:
                             asi en movil la gema puede bajar debajo sin que el
                             numero se estreche. --}}
                        <span class="arena-podium-prize-line">
                            <b>{{ $puesto['premio'] }}</b>
                            {{-- La palabra entera se esconde en movil con CSS,
                                 y el CSS tambien la saca del arbol de
                                 accesibilidad: por eso la unidad completa va
                                 ademas en un texto solo para lectores, o en
                                 movil se anunciaba "10" a secas. --}}
                            <small class="arena-podium-unit" aria-hidden="true">{{ $puesto['premio'] === 1 ? $unidadSingular : $unidadPlural }}</small>
                            <small class="arena-podium-unit-short" aria-hidden="true">{{ $unidadCorta }}</small>
                            <span class="sr-only">{{ $puesto['premio'] === 1 ? $unidadSingular : $unidadPlural }}</span>
                        </span>
                        <img src="{{ asset('images/magnanita-icono.webp') }}?v={{ @filemtime(public_path('images/magnanita-icono.webp')) ?: '1' }}"
                             alt="" class="arena-podium-gema-mini" width="82" height="96" loading="lazy" decoding="async">
                    </span>

                    <span class="arena-podium-who">
                        @if($player)
                            <b>{{ $player->cleanName() }}</b>
                            <span>
                                <x-arena-realm-icon :realm="$player->realm" size="xs" />
                                {{ number_format((float) $player->pl_points, 1) }} PL
                            </span>
                        @else
                            <b>Sin dueño</b>
                            <span>Puede ser tuyo</span>
                        @endif
                    </span>
                </div>

            </div>
        @endforeach
    </div>
</section>
