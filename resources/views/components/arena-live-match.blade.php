@props(['match', 'lineup' => null, 'reportPending' => false])
@php
    use App\Models\Player as PlayerModel;
    use App\Support\ArenaMode;

    $teamSize = ArenaMode::teamSize($match->arena_mode);
    $running = $match->status === 'in_progress' && !$reportPending;

    // El reloj del combate. Cuando todos aceptan, el sistema fija expires_at a
    // la ventana de caza: es el tiempo real que tienen para pelear y reportar.
    // Antes ese plazo existia pero no se veia en ninguna parte, asi que el
    // jugador solo se enteraba de que se le acababa cuando ya se le habia
    // acabado.
    $deadline = $match->expires_at;
    $secondsLeft = $deadline ? max(0, (int) round(now()->diffInSeconds($deadline, false))) : null;
    $totalSeconds = $deadline && $match->started_at
        ? max(1, (int) round($match->started_at->diffInSeconds($deadline)))
        : 1800;

    $radius = 30;
    $circumference = 2 * M_PI * $radius;
    $progress = $secondsLeft === null ? 1 : min(1, max(0, $secondsLeft / $totalSeconds));
    $urgent = $secondsLeft !== null && $secondsLeft <= 300;

    $realmVar = fn ($realm) => 'var(--arena-' . ($realm === 'ignis' ? 'fire' : ($realm === 'alsius' ? 'ice' : 'forest')) . ')';
@endphp

{{-- Combate en curso, dentro del sitio.

     Mismo lenguaje que el aviso de cruce (anillo, alineaciones, figuras) para
     que el jugador no sienta que cambio de aplicacion al pasar de aceptar a
     pelear. La diferencia es lo que mide el reloj: alli el plazo para aceptar,
     aqui el plazo para pelear y reportar. --}}
<section class="arena-duel-panel is-live {{ $reportPending ? 'is-waiting' : '' }}"
         data-live-match
         aria-labelledby="arenaLiveTitle">

    <header class="arena-duel-panel-head">
        <div class="min-w-0">
            <p class="arena-kicker">{{ $match->match_code }} · Arena {{ $match->arena_mode }}</p>
            <h2 id="arenaLiveTitle" class="arena-duel-panel-title">
                @if($reportPending)
                    Esperando confirmación del rival
                @elseif($running)
                    ¡A pelear!
                @else
                    Combate en curso
                @endif
            </h2>
            <p class="arena-duel-panel-sub">
                @if($reportPending)
                    El resultado ya está subido. El rival tiene que confirmarlo para que
                    el ladder lo cuente.
                @else
                    Juega la partida y sube las 2 capturas antes de que se agote el reloj.
                @endif
            </p>
        </div>

        @if(!$reportPending)
            <div class="arena-duel-clock {{ $urgent ? 'is-urgent' : '' }}"
                 data-arena-clock
                 data-clock-expires="{{ $deadline?->timestamp }}"
                 data-clock-total="{{ $totalSeconds }}"
                 data-clock-urgent="300"
                 data-clock-reload="1">
                <svg width="70" height="70" viewBox="0 0 70 70" aria-hidden="true">
                    <circle class="bg" cx="35" cy="35" r="{{ $radius }}"></circle>
                    <circle class="fg" data-clock-arc cx="35" cy="35" r="{{ $radius }}"
                            style="stroke-dasharray: {{ round($circumference, 2) }}; stroke-dashoffset: {{ round($circumference * (1 - $progress), 2) }}"></circle>
                </svg>
                <b data-clock-value>@if($secondsLeft === null)—@else{{ sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) }}@endif</b>
                <span class="arena-duel-clock-note">para pelear</span>
            </div>
        @endif
    </header>

    @if($lineup)
        <div class="arena-duel-lineups">
            @foreach([['own', $lineup['own_realm'], true], ['rival', $lineup['rival_realm'], false]] as [$side, $realm, $isOwn])
                @if(!$isOwn)
                    <div class="arena-duel-versus" aria-hidden="true">VS</div>
                @endif
                <div class="arena-duel-team" style="--team-color: {{ $realmVar($realm) }}">
                    <h3>{{ PlayerModel::REALMS[$realm] ?? $realm }}{{ $isOwn ? ' · tu equipo' : '' }}</h3>
                    @foreach($lineup[$side] as $fighter)
                        <div class="arena-duel-fighter is-ready">
                            <x-arena-champion
                                :id="'live-' . $side . '-' . $loop->index"
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
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    <footer class="arena-duel-panel-foot">
        <div class="arena-duel-zone">
            <span class="arena-duel-zone-key">Zona</span>
            <button type="button" class="arena-duel-zone-value arena-duel-zone-btn" data-modal-open="modal-queue-zone-map"
                    title="Ver el mapa de {{ $match->zone_name }}">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                {{ $match->zone_name }}
            </button>
            <span class="arena-duel-zone-key">·</span>
            <span class="arena-duel-zone-value">{{ $teamSize }} vs {{ $teamSize }}</span>
        </div>

        <div class="arena-duel-actions">
            <a href="{{ route('matches.show', $match) }}" class="{{ $reportPending ? 'arena-btn-secondary' : 'arena-btn' }} px-6 py-2.5">
                {{ $reportPending ? 'Ver el enfrentamiento' : 'Subir el reporte' }}
            </a>
        </div>
    </footer>
</section>
