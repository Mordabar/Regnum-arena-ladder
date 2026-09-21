@props(['match', 'lineup' => null])
@php
    use App\Models\MatchPing;
    use App\Models\Player as PlayerModel;
    use App\Services\MatchPingService;

    $avisos = MatchPing::paraLaBotonera();
    $miId = $lineup['viewer_player_id'] ?? null;

    // El historial ya pintado desde el servidor. Sin esto habria que esperar al
    // primer sondeo -hasta tres segundos- para ver lo que ya se habia dicho, y
    // quien entra a mitad de combate veria la caja vacia.
    $historial = app(MatchPingService::class)->historial(
        $match,
        $miId ? PlayerModel::find($miId) : null
    );
@endphp

{{-- Chat rapido del combate.

     Lo que faltaba no era un chat libre: era poder decir "voy de camino" sin
     salir de la pantalla. Antes el rival solo podia mirar un claro vacio y
     adivinar si el otro venia, se habia muerto por el camino o se habia ido a
     cenar.

     Se comporta como cualquier chat -burbujas a un lado y a otro, lo ultimo
     abajo, aviso con sonido cuando llega algo- pero se escribe con botones:
     diez frases cerradas, sin texto libre. Un chat abierto entre rivales de
     tres reinos seria un problema de moderacion desde el primer dia.

     Empieza plegado en una sola linea. Un combate se juega mirando el mapa y el
     reloj, no una caja de mensajes: la caja se abre cuando hace falta y avisa
     sola cuando el rival dice algo. --}}
<section class="arena-chat" data-pings
         data-pings-match="{{ $match->id }}"
         data-pings-player="{{ $miId }}"
         data-pings-endpoint="{{ route('matches.ping') }}"
         aria-labelledby="arenaChatTitle">

    <button type="button" class="arena-chat-head" data-chat-toggle aria-expanded="false" aria-controls="arenaChatBody">
        <span class="arena-chat-dot" aria-hidden="true"></span>
        <span class="arena-chat-title" id="arenaChatTitle">Avisos del combate</span>

        {{-- Lo ultimo que se dijo, plegado. Asi la linea cerrada ya informa y
             no obliga a abrir para saber si hay algo nuevo. --}}
        <span class="arena-chat-preview" data-chat-preview>
            @if($historial !== [])
                <b>{{ $historial[count($historial) - 1]['nombre'] }}</b>
                {{ $historial[count($historial) - 1]['texto'] }}
            @else
                Avisa al rival sin salir de aqui
            @endif
        </span>

        <span class="arena-chat-badge" data-chat-badge hidden>0</span>
        <svg class="arena-chat-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z" clip-rule="evenodd"/>
        </svg>
    </button>

    <div class="arena-chat-body" id="arenaChatBody" data-chat-body hidden>
        <ol class="arena-chat-log" data-pings-log>
            @forelse($historial as $ping)
                <li class="arena-chat-msg {{ $ping['mio'] ? 'is-mine' : 'is-theirs' }}">
                    <span class="arena-chat-bubble">
                        <span class="arena-chat-who">{{ $ping['nombre'] }}</span>
                        <span class="arena-chat-said"><span aria-hidden="true">{{ $ping['icono'] }}</span> {{ $ping['texto'] }}</span>
                        <time datetime="{{ $ping['en'] }}">{{ $ping['en'] ? \Illuminate\Support\Carbon::parse($ping['en'])->format('H:i') : '' }}</time>
                    </span>
                </li>
            @empty
                <li class="arena-chat-empty" data-pings-empty>
                    Todavia no ha dicho nada nadie. Avisa tu primero.
                </li>
            @endforelse
        </ol>

        @if($miId)
            {{-- La barra de frases. Se desliza de lado como los emotes de
                 cualquier juego: doce botones apilados comerian media pantalla
                 en un movil. --}}
            <div class="arena-chat-quick-wrap">
            <div class="arena-chat-quick" role="group" aria-label="Mandar un aviso">
                @foreach($avisos as $aviso)
                    <button type="button"
                            class="arena-chat-quick-btn is-{{ $aviso['tono'] }}"
                            data-ping-send="{{ $aviso['code'] }}"
                            title="{{ $aviso['texto'] }}">
                        <span aria-hidden="true">{{ $aviso['icono'] }}</span>
                        <span>{{ $aviso['texto'] }}</span>
                    </button>
                @endforeach
            </div>
            </div>
        @endif

        <p class="arena-chat-status" data-pings-status aria-live="polite"></p>
    </div>

    {{-- Lo que ya estaba dicho al cargar. El runtime lo lee para saber por
         donde iba y no sacar bocadillos ni sonar por avisos de hace diez
         minutos la primera vez que sondea. --}}
    <script type="application/json" data-pings-seed>@json($historial)</script>
</section>
