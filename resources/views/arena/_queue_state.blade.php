{{-- En cola: la espera es la parte fragil del ladder.

     El jugador que no sabe si hay alguien al otro lado se va a los dos
     minutos. Aqui ve su guerrero, cuanto lleva esperando y cuanta gente hay
     por reino ahora mismo, refrescado en vivo por el sondeo. --}}
@php
    use App\Support\ArenaMode;
    $queuedPlayer = $players->firstWhere('id', $currentQueue->player_id);
@endphp

<section class="arena-duel-panel is-waiting" data-queue-state aria-labelledby="arenaQueueTitle">
    <header class="arena-duel-panel-head">
        <div class="min-w-0">
            <p class="arena-kicker">{{ $queueTypeLabel }}</p>
            <h2 id="arenaQueueTitle" class="arena-duel-panel-title">
                {{ $currentQueue->queue_type === 'premade' ? 'Tu premade busca rival…' : 'Buscando combate…' }}
            </h2>
            <p class="arena-duel-panel-sub">
                En cuanto haya rival, el cruce aparece aquí mismo con su reloj. No hace
                falta que recargues.
            </p>
        </div>

        <div class="arena-duel-clock is-elapsed"
             data-arena-clock
             data-clock-since="{{ $currentQueue->joined_at?->timestamp }}">
            <b data-clock-value>0:00</b>
            <span class="arena-duel-clock-note">esperando</span>
        </div>
    </header>

    <div class="arena-queue-body">
        @if($queuedPlayer)
            <x-arena-champion
                id="queue-stage"
                :realm="$queuedPlayer->realm"
                :subclass="$queuedPlayer->subclass"
                :race="$queuedPlayer->race"
                :gender="$queuedPlayer->gender"
                :parallax="false"
                height="clamp(200px, 26vh, 280px)"
                class="arena-queue-portrait">
                <div class="arena-champion-overlay">
                    <div class="absolute inset-x-4 bottom-4">
                        <h3 class="arena-champion-name" style="font-size: clamp(17px, 2.4vw, 22px)">{{ $queuedPlayer->cleanName() }}</h3>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-[color:var(--arena-muted)] arena-body-text">
                            <span class="arena-champion-realm">{{ \App\Models\Player::REALMS[$queuedPlayer->realm] ?? $queuedPlayer->realm }}</span>
                            <span>{{ $queuedPlayer->raceName() }}</span>
                            <span>{{ \App\Models\Player::SUBCLASSES[$queuedPlayer->subclass] ?? $queuedPlayer->subclass }}</span>
                        </p>
                    </div>
                </div>
            </x-arena-champion>
        @endif

        <div class="arena-queue-pulse">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <p class="arena-kicker">En cola ahora · {{ ArenaMode::label($currentQueue->arena_mode) }}</p>
                <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                    <span data-queue-pulse-total class="font-semibold text-[color:var(--arena-gold-soft)]">{{ $queuePulse['total'] }}</span>
                    en total
                </p>
            </div>

            <div class="mt-3 flex flex-col gap-2">
                @foreach($queuePulse['realms'] as $pulseRealm)
                    <div class="arena-queue-realm">
                        <span class="inline-flex items-center gap-2 text-sm arena-body-text">
                            <x-arena-realm-icon :realm="$pulseRealm['key']" size="xs" />
                            {{ $pulseRealm['name'] }}
                        </span>
                        <span data-queue-pulse-realm="{{ $pulseRealm['key'] }}"
                              class="font-mono text-lg font-semibold text-white">{{ $pulseRealm['waiting'] }}</span>
                    </div>
                @endforeach
            </div>

            @if($queuePulse['hint'])
                <p data-queue-pulse-hint class="mt-3 text-sm text-[color:var(--arena-sand)] arena-body-text">
                    {{ $queuePulse['hint'] }}
                </p>
            @endif
        </div>
    </div>

    <footer class="arena-duel-panel-foot">
        <div class="arena-duel-zone">
            <span class="arena-duel-zone-key">Expira</span>
            <span class="arena-duel-zone-value">{{ $currentQueue->expires_at?->locale('es')->diffForHumans() ?? 'sin límite' }}</span>
        </div>
        <div class="arena-duel-actions">
            <form method="POST" action="{{ route('queue.leave') }}">
                @csrf
                <input type="hidden" name="player_id" value="{{ $currentQueue->player_id }}">
                <button type="submit" class="arena-btn-danger-ghost px-5 py-2.5">Salir de la cola</button>
            </form>
        </div>
    </footer>
</section>
