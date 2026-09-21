@props(['podio', 'premios'])
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
@endphp

{{-- El podio de la temporada.

     Un ladder sin premios a la vista es una tabla; con ellos es una temporada.
     Esto es lo primero que ve alguien que entra sin cuenta, asi que dice las
     dos cosas que importan de un vistazo: que se reparte y quien lo lleva. --}}
<section class="arena-podium" aria-labelledby="arenaPodiumTitle">
    <header class="arena-podium-head">
        <p class="arena-kicker">Premios de la temporada</p>
        <h2 id="arenaPodiumTitle" class="arena-podium-title">
            <span class="arena-podium-total">{{ $premios->total() }}</span>
            {{ $premios->moneda() }}
        </h2>
        <p class="arena-podium-note">{{ $premios->bases() }}</p>
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
                        <a href="{{ route('ladder.show', $player) }}" class="arena-podium-champion">
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

                    <span class="arena-podium-prize">
                        <b>{{ $puesto['premio'] }}</b>
                        <small>{{ $puesto['premio'] === 1 ? $unidadSingular : $unidadPlural }}</small>
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
