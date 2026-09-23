@props(['match', 'lineup' => null, 'reportPending' => false])
@php
    use App\Models\Player as PlayerModel;
    use App\Support\ArenaMode;

    $teamSize = ArenaMode::teamSize($match->arena_mode);
    // En un duelo "tu equipo" eres tu solo, asi que la etiqueta cambia.
    $esDuelo = ArenaMode::revealsRivalNames($match->arena_mode);
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
            <p class="arena-kicker">{{ $match->match_code }} · {{ \App\Support\ArenaMode::displayName($match->arena_mode) }}</p>
            <h2 id="arenaLiveTitle" class="arena-duel-panel-title">
                @if($reportPending)
                    Esperando confirmación del rival
                @elseif($running)
                    ¡A pelear!
                @else
                    Combate en curso
                @endif
            </h2>
            {{-- Solo cuando hay algo que decir. Con el combate en marcha, el
                 panel ya enseña el reloj, la zona y el formulario del reporte:
                 una linea explicando eso mismo es texto que nadie lee. --}}
            @if($reportPending)
                <p class="arena-duel-panel-sub">
                    El resultado ya está subido. El rival tiene que confirmarlo para que
                    el ladder lo cuente.
                </p>
            @endif
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

    {{-- La zona, arriba del todo.

         Estaba en el pie, debajo del escenario, del chat y del formulario de
         reporte: con el combate en marcha habia que recorrer toda la pantalla
         para mirar DONDE se queda, que es lo primero que se necesita cuando
         salta el cruce. Aqui se ve sin desplazarse.

         El boton llama la atencion hasta que se abre el mapa una vez: quien
         acaba de aceptar no sabe todavia que esto se pulsa. Despues se calma
         solo y no vuelve a molestar en ese combate. --}}
    {{-- El punto de encuentro.

         Antes esto era una linea suelta -"ZONA · nombre · 2 vs 2"- pegada al
         filo de la cabecera, y no decia lo unico que de verdad hace falta
         saber: que el sitio exacto esta marcado en el mapa y que el nombre se
         pulsa para verlo. Ahora es un bloque con rotulo, el boton y la
         instruccion, separado de la cabecera. --}}
    <div class="arena-duel-zone is-destacada">
        <div class="arena-duel-zone-cabecera">
            <span class="arena-duel-zone-key">Punto de encuentro</span>
            <span class="arena-duel-zone-modo">{{ $teamSize }} vs {{ $teamSize }}</span>
        </div>

        <button type="button"
                class="arena-duel-zone-value arena-duel-zone-btn"
                data-modal-open="modal-queue-zone-map"
                data-zone-call="{{ $match->id }}"
                title="Ver el mapa de {{ $match->zone_name }}">
            <svg class="arena-duel-zone-pin" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
            <span class="arena-duel-zone-nombre">{{ $match->zone_name }}</span>
            <span class="arena-duel-zone-cta">Ver el mapa</span>
        </button>

        <p class="arena-duel-zone-pista">
            El sitio exacto va marcado en el mapa. Quedad ahi los
            {{ $teamSize * 2 }} y empezad cuando estéis todos.
        </p>
    </div>

    @if($lineup)
        {{-- El escenario.

             Antes esto eran dos listas con un retrato de 56px al lado del
             nombre: informacion correcta y cero presencia. Ahora las figuras
             mandan, porque son lo que de verdad ayuda a reconocer al rival
             cuando llegas al punto de encuentro -raza, sexo y arquetipo se ven
             de lejos- y porque es donde aparecen los avisos. --}}
        {{-- Escenario y chat, uno al lado del otro.

             Apilados, el panel media mil doscientos pixeles: las figuras
             arriba, el chat debajo y el reporte fuera de la pantalla. Y son
             dos cosas que se usan A LA VEZ -se avisa mirando a quien avisa-,
             asi que tenerlas juntas no es solo ahorrar alto.

             En movil siguen apilados: dos columnas de ciento sesenta pixeles
             no son ni escenario ni chat. --}}
        <div class="arena-live-arena @if($running) has-chat @endif" data-team-size="{{ $teamSize }}">
        <div class="arena-battle" data-battle data-team-size="{{ $teamSize }}">
            @foreach([['own', $lineup['own_realm'], true], ['rival', $lineup['rival_realm'], false]] as [$side, $realm, $isOwn])
                @if(!$isOwn)
                    <div class="arena-battle-vs" aria-hidden="true"><span>VS</span></div>
                @endif

                <div class="arena-battle-side" style="--team-color: {{ $realmVar($realm) }}">
                    <h3>{{ PlayerModel::REALMS[$realm] ?? $realm }}{{ $isOwn ? ($esDuelo ? ' · tú' : ' · tu equipo') : '' }}</h3>

                    <div class="arena-battle-fighters" data-count="{{ count($lineup[$side]) }}">
                        @foreach($lineup[$side] as $fighter)
                            <figure class="arena-battle-fighter"
                                    @if(!empty($fighter['fighter_id'])) data-fighter="{{ $fighter['fighter_id'] }}" @endif>

                                <div class="arena-battle-stage">
                                    {{-- El bocadillo del aviso, DENTRO del
                                         escenario: encima de la figura de quien
                                         lo manda -para no tener que buscar en
                                         una lista quien dijo que- pero sin
                                         salirse del recuadro. Fuera se quedaba
                                         por encima del borde superior y, con la
                                         pagina desplazada hacia la botonera, el
                                         aviso aparecia donde nadie lo veia. --}}
                                    <div class="arena-battle-bubble" data-fighter-bubble hidden aria-live="polite">
                                        <span data-fighter-bubble-icon aria-hidden="true"></span>
                                        <span data-fighter-bubble-text></span>
                                    </div>

                                    <x-arena-champion
                                        :id="'live-' . $side . '-' . $loop->index"
                                        :realm="$realm"
                                        :subclass="$fighter['subclass']"
                                        :race="$fighter['race']"
                                        :gender="$fighter['gender']"
                                        :parallax="false"
                                        height="100%"
                                        class="arena-battle-portrait" />
                                </div>

                                <figcaption>
                                    <b @class(['italic' => !$isOwn && !$lineup['names_revealed']])>{{ $fighter['name'] }}{{ $fighter['is_viewer'] ? ' (tú)' : '' }}</b>
                                    <span>{{ $fighter['subclass_name'] }}</span>
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Los avisos, solo con el combate en marcha. Antes de aceptar no hay
             nada de lo que avisar, y mientras se espera la confirmacion del
             reporte ya se acabo. --}}
        @if($running)
            <x-arena-match-pings :match="$match" :lineup="$lineup" />
        @endif
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
                        <option value="{{ $lineup['own_side'] }}">{{ $esDuelo ? 'Yo' : 'Tu equipo' }} ({{ PlayerModel::REALMS[$lineup['own_realm']] ?? $lineup['own_realm'] }})</option>
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

                <button type="submit" class="arena-btn w-full" data-report-submit><x-arena-icon name="send" class="h-4 w-4 shrink-0" />Enviar reporte</button>
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
                    <button type="submit" class="arena-btn px-5 py-2.5"><x-arena-icon name="check" class="h-4 w-4 shrink-0" />Confirmar resultado</button>
                </form>
                <button type="button" class="arena-btn-danger-ghost px-5 py-2.5" data-reject-toggle><x-arena-icon name="x" class="h-4 w-4 shrink-0" />Rechazar y explicar</button>
            </div>

            @php
                // Si el envio anterior fallo, el formulario tiene que volver
                // ABIERTO y con el error dentro. Cerrado y con el aviso arriba
                // del todo, el jugador -que esta mirando el panel de combate,
                // abajo- solo ve que pulsar no hace nada.
                // Se mira el error, no la entrada anterior: old() depende de que
                // el fallo haya venido de una validacion, y los nuestros -los de
                // base de datos- no la pasan.
                $rechazoFallido = $errors->has('rejection_note')
                    || $errors->has('rejection_files')
                    || $errors->has('rejection_files.*')
                    || $errors->has('error');
            @endphp

            {{-- El rechazo admite capturas. Sin ellas moderacion tiene la
                 version del otro con pruebas y la tuya sin ninguna, asi que se
                 pide aunque no se obligue: quien no tomo captura tiene que
                 poder rechazar igual, o se tragaria un resultado falso. --}}
            <form method="POST" action="{{ route('matches.report.reject') }}" class="arena-report-reject"
                  data-reject-form enctype="multipart/form-data" @if(! $rechazoFallido) hidden @endif>
                @csrf
                <input type="hidden" name="report_id" value="{{ $report->id }}">
                <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">

                @if($rechazoFallido)
                    <div class="mb-4 rounded-xl border border-rose-500/40 bg-rose-950/40 px-4 py-3 text-sm text-rose-100">
                        @foreach($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Por que lo rechazas</span>
                    <textarea name="rejection_note" rows="3" class="arena-textarea" required minlength="5"
                              placeholder="Cuenta que paso de verdad. Lo lee moderacion, no el rival.">{{ old('rejection_note') }}</textarea>
                </label>
                <label class="block mt-4">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Tus capturas (opcional, hasta 3)</span>
                    <input type="file" name="rejection_files[]" accept="image/*" class="arena-field text-sm" multiple>
                    <span class="mt-2 block text-xs text-[color:var(--arena-muted)]">
                        Con pruebas el arbitraje es mucho mas rapido. Mismos formatos que el reporte, hasta 10 MB cada una.
                    </span>
                </label>
                <button type="submit" class="arena-btn-danger px-5 py-2.5 mt-4"><x-arena-icon name="send" class="h-4 w-4 shrink-0" />Enviar el rechazo</button>
            </form>
        </div>
    @endif

    <footer class="arena-duel-panel-foot">
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
