@props(['match', 'lineup', 'player' => null])
@php
    use App\Models\Player as PlayerModel;

    $waiting = $lineup['viewer_accepted'];
    $teamSize = \App\Support\ArenaMode::teamSize($match->arena_mode);
    $secondsLeft = $match->expires_at ? max(0, now()->diffInSeconds($match->expires_at, false)) : null;
    $totalSeconds = $match->expires_at && $match->created_at
        ? max(1, $match->created_at->diffInSeconds($match->expires_at))
        : 300;

    // El anillo se dibuja ya con el tiempo que queda de verdad: sin JavaScript
    // sigue diciendo algo cierto en vez de aparecer siempre lleno.
    $radius = 34;
    $circumference = 2 * M_PI * $radius;
    $progress = $secondsLeft === null ? 1 : min(1, max(0, $secondsLeft / $totalSeconds));
@endphp

<div class="arena-duel"
     id="arenaDuelOverlay"
     role="dialog"
     aria-modal="true"
     aria-labelledby="arenaDuelTitle"
     data-duel-overlay
     data-duel-expires="{{ $match->expires_at?->timestamp }}"
     data-duel-total="{{ $totalSeconds }}"
     data-duel-waiting="{{ $waiting ? '1' : '0' }}">

    <div class="arena-duel-card">

        <header class="arena-duel-head">
            <p class="arena-kicker">{{ $match->match_code }} · Arena {{ $match->arena_mode }}</p>
            <h2 id="arenaDuelTitle" class="arena-duel-title">
                {{ $waiting ? 'Esperando a los demás' : '¡Combate encontrado!' }}
            </h2>
            <p class="arena-duel-sub">
                @if($waiting)
                    Ya has aceptado. La partida arranca en cuanto confirmen los
                    {{ $lineup['player_count'] }} jugadores.
                @else
                    Acepta antes de que se agote el tiempo o la partida se cancela para todos.
                @endif
            </p>

            <div class="arena-duel-ring" data-duel-ring>
                <svg width="78" height="78" viewBox="0 0 78 78" aria-hidden="true">
                    <circle class="bg" cx="39" cy="39" r="{{ $radius }}"></circle>
                    <circle class="fg" data-duel-arc cx="39" cy="39" r="{{ $radius }}"
                            style="stroke-dasharray: {{ round($circumference, 2) }}; stroke-dashoffset: {{ round($circumference * (1 - $progress), 2) }}"></circle>
                </svg>
                <b data-duel-clock>
                    @if($secondsLeft === null)
                        —
                    @else
                        {{ sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60) }}
                    @endif
                </b>
            </div>
            <p class="arena-duel-count">
                <span data-duel-accepted>{{ $lineup['accepted_count'] }}</span>
                de {{ $lineup['player_count'] }} han aceptado
            </p>
        </header>

        <div class="arena-duel-body">

            @if($player)
                {{-- Tu guerrero, en el momento en que decides si peleas con el. --}}
                <x-arena-champion
                    id="duel-stage"
                    :realm="$player->realm"
                    :subclass="$player->subclass"
                    height="230px"
                    :parallax="false"
                    class="arena-duel-stage" />
            @endif

            <div class="arena-duel-lineups">
                <div class="arena-duel-team" style="--team-color: var(--arena-{{ $lineup['own_realm'] === 'ignis' ? 'fire' : ($lineup['own_realm'] === 'alsius' ? 'ice' : 'forest') }})">
                    <h3>{{ PlayerModel::REALMS[$lineup['own_realm']] ?? $lineup['own_realm'] }} · tu equipo</h3>
                    @foreach($lineup['own'] as $fighter)
                        <div class="arena-duel-fighter {{ $fighter['accepted'] ? 'is-ready' : '' }}">
                            <span class="arena-duel-avatar">
                                <x-arena-realm-icon :realm="$lineup['own_realm']" size="sm" />
                            </span>
                            <span class="min-w-0">
                                <b>{{ $fighter['name'] }}{{ $fighter['is_viewer'] ? ' (tú)' : '' }}</b>
                                <span>{{ $fighter['subclass_name'] }}</span>
                            </span>
                            <span class="arena-duel-ready">{{ $fighter['accepted'] ? 'Listo' : 'Esperando' }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="arena-duel-versus" aria-hidden="true">VS</div>

                <div class="arena-duel-team" style="--team-color: var(--arena-{{ $lineup['rival_realm'] === 'ignis' ? 'fire' : ($lineup['rival_realm'] === 'alsius' ? 'ice' : 'forest') }})">
                    <h3>{{ PlayerModel::REALMS[$lineup['rival_realm']] ?? $lineup['rival_realm'] }}</h3>
                    @foreach($lineup['rival'] as $fighter)
                        <div class="arena-duel-fighter {{ $fighter['accepted'] ? 'is-ready' : '' }}">
                            <span class="arena-duel-avatar">
                                <x-arena-realm-icon :realm="$lineup['rival_realm']" size="sm" />
                            </span>
                            <span class="min-w-0">
                                <b @class(['italic' => !$lineup['names_revealed']])>{{ $fighter['name'] }}</b>
                                <span>{{ $fighter['subclass_name'] }}</span>
                            </span>
                            <span class="arena-duel-ready">{{ $fighter['accepted'] ? 'Listo' : 'Esperando' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="arena-duel-zone">
                <div>
                    <p class="arena-duel-zone-key">Zona asignada</p>
                    <p class="arena-duel-zone-value">{{ $match->zone_name }}</p>
                </div>
                <div style="text-align: right">
                    <p class="arena-duel-zone-key">Formato</p>
                    <p class="arena-duel-zone-value">{{ $teamSize }} vs {{ $teamSize }}</p>
                </div>
            </div>
        </div>

        <footer class="arena-duel-foot">
            @if($waiting)
                <a href="{{ route('matches.show', $match) }}" class="arena-btn-secondary flex-1">Ver el enfrentamiento</a>
            @else
                {{-- Formularios de verdad: aceptar y rechazar funcionan aunque el
                     navegador no ejecute JavaScript. --}}
                <form method="POST" action="{{ route('matches.accept') }}" class="flex-1">
                    @csrf
                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                    <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">
                    <input type="hidden" name="from" value="queue">
                    <button type="submit" class="arena-btn w-full" data-duel-accept>
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        Aceptar combate
                    </button>
                </form>
                {{-- El aviso ya ocupa la pantalla entera, asi que la confirmacion
                     va en un confirm() del navegador y no en otro modal encima:
                     apilar dos capas para una sola decision es peor. --}}
                <form method="POST" action="{{ route('matches.reject') }}"
                      onsubmit="return confirm('Si rechazas, el combate se cancela y los demás vuelven a la cola. Rechazar a menudo puede acarrear sanciones. ¿Seguro?')">
                    @csrf
                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                    <input type="hidden" name="player_id" value="{{ $lineup['viewer_player_id'] }}">
                    <button type="submit" class="arena-btn-danger-ghost">Rechazar</button>
                </form>
            @endif
        </footer>
    </div>
</div>

@push('scripts')
<script>
    /* Reloj del cruce.
       El anillo ya llega pintado con el tiempo real desde el servidor: esto solo
       lo mantiene vivo. Sin JavaScript el aviso sigue siendo usable, con la
       cuenta congelada en el momento de cargar la pagina. */
    (function () {
        var overlay = document.getElementById('arenaDuelOverlay');
        if (!overlay) { return; }

        var ring = overlay.querySelector('[data-duel-ring]');
        var arc = overlay.querySelector('[data-duel-arc]');
        var clock = overlay.querySelector('[data-duel-clock]');
        var expires = parseInt(overlay.dataset.duelExpires || '0', 10);
        var total = Math.max(1, parseInt(overlay.dataset.duelTotal || '300', 10));
        var circumference = arc ? parseFloat(arc.style.strokeDasharray) : 0;
        var reloaded = false;

        function tick() {
            if (!expires) { return; }

            var left = Math.max(0, expires - Math.floor(Date.now() / 1000));
            var minutes = Math.floor(left / 60);
            var seconds = left % 60;

            clock.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

            var ratio = Math.min(1, left / total);
            if (arc) { arc.style.strokeDashoffset = (circumference * (1 - ratio)).toFixed(2); }
            ring.classList.toggle('is-urgent', left <= 20);

            // Al agotarse, el servidor cancela el cruce: se recarga una sola vez
            // para que el jugador vea lo que ha pasado en vez de un reloj a cero.
            if (left === 0 && !reloaded) {
                reloaded = true;
                window.setTimeout(function () { window.location.reload(); }, 1200);
            }
        }

        tick();
        window.setInterval(tick, 1000);

        /* Mientras el aviso esta abierto, el foco no se escapa detras de el. */
        var previouslyFocused = document.activeElement;
        var focusables = overlay.querySelectorAll('button, [href], input:not([type="hidden"]), select, textarea');
        var first = focusables[0];
        var last = focusables[focusables.length - 1];

        // preventScroll: enfocar el boton de aceptar arrastraba la vista hasta
        // el pie y dejaba el titulo y el reloj por encima del borde en movil.
        if (first) {
            try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
            overlay.scrollTop = 0;
        }

        overlay.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab' || focusables.length === 0) { return; }

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        // Un cruce no se cierra con Escape: hay que aceptarlo o rechazarlo. Se
        // captura para que la tecla no haga otra cosa por detras.
        overlay.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { event.preventDefault(); }
        });

        document.body.style.overflow = 'hidden';
        window.addEventListener('pagehide', function () {
            document.body.style.overflow = '';
            if (previouslyFocused && previouslyFocused.focus) { previouslyFocused.focus(); }
        });
    })();
</script>
@endpush
