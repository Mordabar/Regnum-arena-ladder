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

    // La abreviatura del cajon en movil: "10 L" en vez de "10 lingotes". Con la
    // palabra entera no cabia y habia que esconderla, y entonces el cajon solo
    // decia un numero suelto que no significaba nada.
    $unidadCorta = mb_strtoupper(mb_substr($unidadPlural, 0, 1));
@endphp

{{-- El podio de la temporada.

     Un ladder sin premios a la vista es una tabla; con ellos es una temporada.
     Esto es lo primero que ve alguien que entra sin cuenta, asi que dice las
     dos cosas que importan de un vistazo: que se reparte y quien lo lleva. --}}
<section class="arena-podium" aria-labelledby="arenaPodiumTitle">
    <header class="arena-podium-head">
        {{-- El trofeo abre la seccion. Un premio se anuncia con la copa, no con
             una linea de texto en versalitas: es lo que hace que la primera
             pantalla se lea como un torneo y no como una tabla. --}}
        <svg class="arena-podium-cup" viewBox="0 0 48 48" aria-hidden="true">
            <defs>
                <linearGradient id="arenaCupOro" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#ffe9a8" />
                    <stop offset=".45" stop-color="#e3b75c" />
                    <stop offset="1" stop-color="#9d7526" />
                </linearGradient>
            </defs>
            {{-- Las asas --}}
            <path d="M13 12H8a6 6 0 0 0 6 10M35 12h5a6 6 0 0 1-6 10"
                  fill="none" stroke="url(#arenaCupOro)" stroke-width="2.6" stroke-linecap="round" />
            {{-- La copa --}}
            <path d="M13 8h22v11a11 11 0 0 1-22 0z" fill="url(#arenaCupOro)" />
            {{-- El pie --}}
            <path d="M22 30h4v6h-4z" fill="url(#arenaCupOro)" />
            <path d="M15 39h18a1.6 1.6 0 0 1 1.6 1.6V42H13.4v-1.4A1.6 1.6 0 0 1 15 39z" fill="url(#arenaCupOro)" />
            {{-- El brillo, que es lo que lo hace parecer metal y no una silueta --}}
            <path d="M17 10h3v9a7 7 0 0 0 2 4.9A8 8 0 0 1 17 17z" fill="#fff6dc" opacity=".55" />
        </svg>

        <p class="arena-kicker">Premios de la temporada</p>
        <h2 id="arenaPodiumTitle" class="arena-podium-title">
            <img src="{{ asset('images/magnanita.webp') }}" alt="" class="arena-podium-gema" width="256" height="208" loading="lazy" decoding="async">
            <span>
                <span class="arena-podium-total">{{ $premios->total() }}</span>
                {{ $premios->moneda() }}
            </span>
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

                    {{-- El premio con su gema. La palabra entera en pantalla
                         grande y la inicial en movil: "10 L" sigue diciendo de
                         que va, y el cristal al lado lo remata. Un numero
                         suelto, que es lo que habia, no decia nada. --}}
                    <span class="arena-podium-prize">
                        <b>{{ $puesto['premio'] }}</b>
                        <small class="arena-podium-unit">{{ $puesto['premio'] === 1 ? $unidadSingular : $unidadPlural }}</small>
                        <small class="arena-podium-unit-short" aria-hidden="true">{{ $unidadCorta }}</small>
                        <img src="{{ asset('images/magnanita-icono.webp') }}" alt="" class="arena-podium-gema-mini" width="82" height="96" loading="lazy" decoding="async">
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
