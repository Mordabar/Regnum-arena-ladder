@props(['match', 'lineup' => null])
@php
    use App\Models\MatchPing;
    use App\Services\MatchPingService;

    $avisos = MatchPing::paraLaBotonera();
    $miId = $lineup['viewer_player_id'] ?? null;

    // El historial ya pintado desde el servidor. Sin esto habria que esperar al
    // primer sondeo -hasta tres segundos- para ver lo que ya se habia dicho, y
    // quien entra a mitad de combate veria la caja vacia.
    // Con el id basta: de quien mira solo se necesita saber de que bando es.
    $historial = app(MatchPingService::class)->historial($match, $miId ? (int) $miId : null);
@endphp

{{-- Mini chat del combate.

     Lo que faltaba no era un chat libre: era poder decir "voy de camino" sin
     salir de la pantalla. Antes el rival solo podia mirar un claro vacio y
     adivinar si el otro venia, se habia muerto por el camino o se habia ido a
     cenar.

     Se comporta como cualquier chat -burbujas a un lado y a otro, lo ultimo
     abajo, aviso con sonido- pero se escribe con botones: seis frases cerradas,
     sin texto libre. Un chat abierto entre rivales de tres reinos seria un
     problema de moderacion desde el primer dia.

     Siempre abierto y con las seis frases a la vista. Plegado no servia: se
     usa con prisa, a mitad de un combate, y cualquier paso de mas -abrir la
     caja, arrastrar una barra para encontrar el boton- es un paso que no se
     da. --}}
<section class="arena-chat" data-pings
         data-pings-match="{{ $match->id }}"
         data-pings-player="{{ $miId }}"
         data-pings-endpoint="{{ route('matches.ping') }}"
         aria-labelledby="arenaChatTitle">

    <div class="arena-chat-head">
        <span class="arena-chat-dot" aria-hidden="true"></span>
        <span class="arena-chat-title" id="arenaChatTitle">Avisos del combate</span>
        <span class="arena-chat-badge" data-chat-badge hidden>0</span>
    </div>

    <div class="arena-chat-body" data-chat-body>
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
            {{-- Las seis frases, todas a la vista y en rejilla.
                 Antes iban en una barra que se arrastraba de lado: con raton no
                 se arrastra, asi que en escritorio la mitad de las frases no
                 existian. --}}
            <div class="arena-chat-quick" role="group" aria-label="Mandar un aviso">
                @foreach($avisos as $aviso)
                    <button type="button"
                            class="arena-chat-quick-btn is-{{ $aviso['tono'] }}"
                            data-ping-send="{{ $aviso['code'] }}"
                            title="{{ $aviso['texto'] }}">
                        <span class="arena-chat-quick-icon" aria-hidden="true">{{ $aviso['icono'] }}</span>
                        <span class="arena-chat-quick-text">{{ $aviso['texto'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <p class="arena-chat-status" data-pings-status aria-live="polite"></p>
    </div>

    {{-- Lo que ya estaba dicho al cargar. El runtime lo lee para saber por
         donde iba y no sacar bocadillos ni sonar por avisos de hace diez
         minutos la primera vez que sondea. --}}
    <script type="application/json" data-pings-seed>@json($historial)</script>
</section>
