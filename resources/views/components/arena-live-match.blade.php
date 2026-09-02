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

    // Que le toca hacer a quien mira: subir el reporte, contestar al del rival,
    // o solo esperar. Todo ocurre en esta misma pantalla.
    $report = $match->report;
    $viewerCanReport = $lineup && $match->status === 'in_progress' && !$report;
    $viewerCanAnswerReport = $lineup
        && $report
        && $report->status === 'pending_confirmation'
        && $lineup['own_side'] !== $report->reporting_team;
    // Abierto de entrada: si el combate ya termino, subirlo es lo unico que
    // queda por hacer.
    $reportOpen = $viewerCanReport;

    $claimedWinnerLabel = match ($report?->claimed_winner_team) {
        'draw' => 'nadie, fue empate',
        $lineup['own_side'] ?? null => 'tu equipo',
        default => 'su equipo',
    };
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

    @if($lineup && $viewerCanReport)
        {{-- El reporte se sube aqui.
             Antes "Subir el reporte" saltaba a otra pagina, con otra cabecera y
             otro contador de pasos: el jugador sentia que cambiaba de
             aplicacion justo en el paso que cierra la partida. --}}
        <details class="arena-report-inline" @if($reportOpen) open @endif>
            <summary>
                <span>Subir el reporte del combate</span>
                <span class="arena-report-inline-hint">1 a 3 capturas y quien gano</span>
            </summary>

            <form method="POST" action="{{ route('matches.report') }}" enctype="multipart/form-data" class="arena-report-inline-body" data-report-form>
                @csrf
                <input type="hidden" name="match_id" value="{{ $match->id }}">
                <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">

                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Equipo ganador</span>
                    <select name="claimed_winner_team" class="arena-select">
                        <option value="{{ $lineup['own_side'] }}">Tu equipo ({{ PlayerModel::REALMS[$lineup['own_realm']] ?? $lineup['own_realm'] }})</option>
                        <option value="{{ $lineup['rival_side'] }}">Rival ({{ PlayerModel::REALMS[$lineup['rival_realm']] ?? $lineup['rival_realm'] }})</option>
                        <option value="draw">Empate, sin ganador</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Capturas del combate terminado</span>
                    <input type="file" name="evidence_files[]" accept="image/*" class="arena-field text-sm" required multiple>
                    <span class="mt-2 block text-xs text-[color:var(--arena-muted)] arena-body-text">
                        Entre 1 y 3 imagenes. JPG, PNG, WEBP, GIF, BMP, AVIF o HEIC, hasta 10 MB cada una.
                    </span>
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Nota opcional</span>
                    <textarea name="reporter_note" rows="2" class="arena-textarea" placeholder="Contexto extra para el rival o el admin"></textarea>
                </label>

                <button type="submit" class="arena-btn w-full" data-report-submit>Enviar reporte</button>
            </form>
        </details>
    @endif

    @if($lineup && $viewerCanAnswerReport)
        {{-- Y al otro lado, la respuesta: tambien aqui. --}}
        <div class="arena-report-inline is-answer">
            <p class="arena-report-inline-lead">
                El rival reporto que gano
                <b>{{ $claimedWinnerLabel }}</b>.
                Confirma si es correcto, o rechazalo y explica por que.
            </p>

            <div class="arena-duel-actions">
                <form method="POST" action="{{ route('matches.report.confirm') }}">
                    @csrf
                    <input type="hidden" name="report_id" value="{{ $report->id }}">
                    <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">
                    <button type="submit" class="arena-btn px-5 py-2.5">Confirmar resultado</button>
                </form>
                <button type="button" class="arena-btn-danger-ghost px-5 py-2.5" data-reject-toggle>Rechazar y explicar</button>
            </div>

            <form method="POST" action="{{ route('matches.report.reject') }}" class="arena-report-reject" data-reject-form hidden>
                @csrf
                <input type="hidden" name="report_id" value="{{ $report->id }}">
                <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">
                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Por que lo rechazas</span>
                    <textarea name="rejection_note" rows="3" class="arena-textarea" required
                              placeholder="Cuenta que paso de verdad. Lo lee moderacion, no el rival."></textarea>
                </label>
                <button type="submit" class="arena-btn-danger px-5 py-2.5">Enviar el rechazo</button>
            </form>
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

        {{-- Mientras el enfrentamiento esta vivo no hay boton para irse: lo
             unico que queda por hacer es reportar y responder, y las dos cosas
             estan aqui arriba. El historial completo se consulta despues, desde
             "Mis combates". --}}
        <div class="arena-duel-actions">
            <span class="arena-duel-zone-key">
                @if($reportPending)
                    El resultado ya viaja al rival
                @else
                    El enfrentamiento se cierra en cuanto reportes
                @endif
            </span>
        </div>
    </footer>
</section>

@push('scripts')
<script>
    /* Subir 3 imagenes tarda. Sin senal el jugador vuelve a pulsar y manda el
       reporte dos veces. */
    (function () {
        var form = document.querySelector('[data-report-form]');
        if (!form) { return; }

        form.addEventListener('submit', function () {
            var button = form.querySelector('[data-report-submit]');
            if (!button) { return; }

            button.disabled = true;
            button.textContent = 'Subiendo el reporte…';
        });
    })();

    /* Rechazar pide un motivo, y ese motivo no puede estar en otra pagina. */
    (function () {
        var toggle = document.querySelector('[data-reject-toggle]');
        var rejectForm = document.querySelector('[data-reject-form]');
        if (!toggle || !rejectForm) { return; }

        toggle.addEventListener('click', function () {
            rejectForm.hidden = !rejectForm.hidden;
            if (!rejectForm.hidden) { rejectForm.querySelector('textarea').focus(); }
        });
    })();
</script>
@endpush
