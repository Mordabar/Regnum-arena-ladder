@props(['match', 'lineup', 'player' => null])
@php
    use App\Models\Player as PlayerModel;

    $waiting = $lineup['viewer_accepted'];
    $teamSize = \App\Support\ArenaMode::teamSize($match->arena_mode);
    $secondsLeft = $match->expires_at ? max(0, now()->diffInSeconds($match->expires_at, false)) : null;
    $totalSeconds = $match->expires_at && $match->created_at
        ? max(1, $match->created_at->diffInSeconds($match->expires_at))
        : 300;

    // El anillo llega ya con el tiempo real: sin JavaScript sigue diciendo algo
    // cierto en vez de aparecer siempre lleno.
    $radius = 30;
    $circumference = 2 * M_PI * $radius;
    $progress = $secondsLeft === null ? 1 : min(1, max(0, $secondsLeft / $totalSeconds));

    $realmVar = fn ($realm) => 'var(--arena-' . ($realm === 'ignis' ? 'fire' : ($realm === 'alsius' ? 'ice' : 'forest')) . ')';
@endphp

{{-- Aviso de cruce, dentro de la pagina.

     Antes era una capa a pantalla completa. Un cruce no es una interrupcion
     ajena a lo que estabas haciendo: es LO que estabas haciendo, asi que vive
     en el sitio, con su reloj y sus alineaciones, y sin tapar el resto. --}}
<section class="arena-duel-panel {{ $waiting ? 'is-waiting' : '' }}"
         data-duel-panel
         aria-labelledby="arenaDuelTitle">

    <header class="arena-duel-panel-head">
        <div class="min-w-0">
            <p class="arena-kicker">{{ $match->match_code }} · Arena {{ $match->arena_mode }}</p>
            <h2 id="arenaDuelTitle" class="arena-duel-panel-title">
                {{ $waiting ? 'Esperando a los demás' : '¡Combate encontrado!' }}
            </h2>
            <p class="arena-duel-panel-sub">
                @if($waiting)
                    Ya has aceptado. La partida arranca en cuanto confirmen los
                    {{ $lineup['player_count'] }} jugadores.
                @else
                    Acepta antes de que se agote el tiempo o la partida se cancela para todos.
                @endif
            </p>
        </div>

        <div class="arena-duel-clock"
             data-arena-clock
             data-clock-expires="{{ $match->expires_at?->timestamp }}"
             data-clock-total="{{ $totalSeconds }}"
             data-clock-urgent="20"
             data-clock-reload="1">
            <svg width="70" height="70" viewBox="0 0 70 70" aria-hidden="true">
                <circle class="bg" cx="35" cy="35" r="{{ $radius }}"></circle>
                <circle class="fg" data-clock-arc cx="35" cy="35" r="{{ $radius }}"
                        style="stroke-dasharray: {{ round($circumference, 2) }}; stroke-dashoffset: {{ round($circumference * (1 - $progress), 2) }}"></circle>
            </svg>
            <b data-clock-value>
                @if($secondsLeft === null)—@else{{ sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) }}@endif
            </b>
            <span class="arena-duel-clock-note">
                <span data-duel-accepted>{{ $lineup['accepted_count'] }}</span>/{{ $lineup['player_count'] }} listos
            </span>
        </div>
    </header>

    <div class="arena-duel-lineups">
        @foreach([['own', $lineup['own_realm'], true], ['rival', $lineup['rival_realm'], false]] as [$side, $realm, $isOwn])
            @if(!$isOwn)
                <div class="arena-duel-versus" aria-hidden="true">VS</div>
            @endif
            <div class="arena-duel-team" style="--team-color: {{ $realmVar($realm) }}">
                <h3>{{ PlayerModel::REALMS[$realm] ?? $realm }}{{ $isOwn ? ' · tu equipo' : '' }}</h3>
                @foreach($lineup[$side] as $fighter)
                    {{-- Cada combatiente con su propio guerrero en 3D. Son
                         escenarios pequenos y sin parallax: lo que importa aqui
                         es reconocer de un vistazo a quien tienes al lado y
                         enfrente, no lucir el modelo. --}}
                    <div class="arena-duel-fighter {{ $fighter['accepted'] ? 'is-ready' : '' }}">
                        <x-arena-champion
                            :id="'duel-' . $side . '-' . $loop->index"
                            :realm="$realm"
                            :subclass="$fighter['subclass']"
                            :race="$fighter['race']"
                            :gender="$fighter['gender']"
                            :parallax="false"
                            height="76px"
                            class="arena-duel-portrait" />
                        <span class="min-w-0">
                            <b @class(['italic' => !$isOwn && !$lineup['names_revealed']])>{{ $fighter['name'] }}{{ $fighter['is_viewer'] ? ' (tú)' : '' }}</b>
                            <span>{{ $fighter['subclass_name'] }}</span>
                        </span>
                        <span class="arena-duel-ready">{{ $fighter['accepted'] ? 'Listo' : 'Esperando' }}</span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <footer class="arena-duel-panel-foot">
        <div class="arena-duel-zone">
            <span class="arena-duel-zone-key">Zona</span>
            <span class="arena-duel-zone-value">{{ $match->zone_name }}</span>
            <span class="arena-duel-zone-key">·</span>
            <span class="arena-duel-zone-value">{{ $teamSize }} vs {{ $teamSize }}</span>
        </div>

        <div class="arena-duel-actions">
            @if($waiting)
                <a href="{{ route('matches.show', $match) }}" class="arena-btn-secondary px-5 py-2.5">Ver el enfrentamiento</a>
            @else
                {{-- Formularios de verdad: funcionan aunque no haya JavaScript. --}}
                <form method="POST" action="{{ route('matches.accept') }}">
                    @csrf
                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                    <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">
                    <input type="hidden" name="from" value="queue">
                    <button type="submit" class="arena-btn px-6 py-2.5" data-duel-accept>
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        Aceptar combate
                    </button>
                </form>
                <form method="POST" action="{{ route('matches.reject') }}"
                      onsubmit="return confirm('Si rechazas, el combate se cancela y los demás vuelven a la cola. Rechazar a menudo puede acarrear sanciones. ¿Seguro?')">
                    @csrf
                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                    <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">
                    <button type="submit" class="arena-btn-danger-ghost px-5 py-2.5">Rechazar</button>
                </form>
            @endif
        </div>
    </footer>
</section>
