@extends('layouts.arena')

@section('title', 'Moderar ' . $match->match_code)

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Matches', 'url' => route('admin.matches.index')],
        ['label' => $match->match_code],
    ]" class="mb-6" />

    {{-- Hero --}}
    <section class="arena-panel-strong mb-6 p-6 md:p-8 arena-animate-in relative overflow-hidden">
        <div class="absolute -top-16 -left-16 w-48 h-48 rounded-full pointer-events-none opacity-20"
             style="background: radial-gradient(circle, {{ $match->team_a_realm === 'ignis' ? 'rgba(211,100,47,0.5)' : ($match->team_a_realm === 'alsius' ? 'rgba(121,181,214,0.5)' : 'rgba(142,179,74,0.5)') }}, transparent 70%)">
        </div>
        <div class="absolute -top-16 -right-16 w-48 h-48 rounded-full pointer-events-none opacity-20"
             style="background: radial-gradient(circle, {{ $match->team_b_realm === 'ignis' ? 'rgba(211,100,47,0.5)' : ($match->team_b_realm === 'alsius' ? 'rgba(121,181,214,0.5)' : 'rgba(142,179,74,0.5)') }}, transparent 70%)">
        </div>

        <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <p class="arena-kicker">Moderación</p>
                    @php
                        $heroStatusClass = match($match->status) {
                            'pending_acceptance' => 'arena-status-pending',
                            'in_progress' => 'arena-status-active',
                            'completed' => 'arena-status-completed',
                            'disputed' => 'arena-status-disputed',
                            'void' => 'arena-status-void',
                            default => 'arena-status-pending',
                        };
                    @endphp
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $heroStatusClass }}">{{ $match->status_name }}</span>
                </div>
                <h1 class="mt-3 text-4xl font-bold text-white md:text-5xl">{{ $match->match_code }}</h1>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 text-[color:var(--arena-sand)] arena-body-text">
                        <x-arena-realm-icon :realm="$match->team_a_realm" size="sm" />
                        {{ \App\Models\ArenaMatch::REALMS[$match->team_a_realm] ?? strtoupper((string) $match->team_a_realm) }}
                    </span>
                    <span class="text-[color:var(--arena-muted)]">vs</span>
                    <span class="inline-flex items-center gap-1.5 text-[color:var(--arena-sand)] arena-body-text">
                        <x-arena-realm-icon :realm="$match->team_b_realm" size="sm" />
                        {{ \App\Models\ArenaMatch::REALMS[$match->team_b_realm] ?? strtoupper((string) $match->team_b_realm) }}
                    </span>
                    <button type="button" class="flex items-center gap-2 rounded-lg border border-[color:var(--arena-gold-soft)]/20 bg-[color:var(--arena-gold-soft)]/10 px-3 py-1.5 text-xs font-semibold text-[#f4deb1] transition-all hover:bg-[color:var(--arena-gold-soft)]/20 shadow-sm ml-2" data-modal-open="modal-admin-zone-map">
                        <svg class="h-4 w-4 drop-shadow-md text-[color:var(--arena-gold)]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                        Ver Mapa ({{ $match->zone_name }})
                    </button>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.inbox') }}" class="arena-btn-warning px-4 py-2">Inbox</a>
                <a href="{{ route('admin.matches.index') }}" class="arena-btn-ghost px-4 py-2">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
                    Matches
                </a>
            </div>
        </div>
    </section>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Teams --}}
        @foreach(['team_a', 'team_b'] as $side)
            @php $realm = $side === 'team_a' ? $match->team_a_realm : $match->team_b_realm; @endphp
            <section class="arena-panel arena-card-{{ $realm }} p-6 arena-animate-in arena-stagger-{{ $loop->index + 1 }}">
                <div class="flex items-center gap-2 mb-4">
                    <x-arena-realm-icon :realm="$realm" size="md" />
                    <div>
                        <p class="arena-kicker">{{ $side === 'team_a' ? 'Team A' : 'Team B' }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-white">{{ \App\Models\ArenaMatch::REALMS[$realm] ?? strtoupper((string) $realm) }}</h2>
                    </div>
                </div>
                <div class="space-y-2">
                    @foreach($match->getTeamBySide($side) as $player)
                        <article class="arena-card p-3">
                            <p class="font-semibold text-white arena-body-text">{{ $player['character_name'] }}</p>
                            <p class="text-xs text-[color:var(--arena-muted)] arena-body-text">
                                {{ \App\Models\Player::SUBCLASSES[$player['subclass']] ?? ucfirst($player['subclass']) }}
                                @if(!empty($player['conjurer_role'])) · {{ ucfirst($player['conjurer_role']) }} @endif
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Report --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-3">
            <h2 class="text-2xl font-semibold text-white">Reporte</h2>
            @if($match->report)
                <div class="mt-5 space-y-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="arena-card p-4">
                            <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Estado</p>
                            <p class="mt-1 font-semibold text-white arena-body-text">{{ $match->report->status_name }}</p>
                        </div>
                        <div class="arena-card p-4">
                            <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Reporter</p>
                            <p class="mt-1 font-semibold text-white arena-body-text">{{ $match->report->reporter?->character_name ?? 'Sin reporter' }}</p>
                        </div>
                        <div class="arena-card p-4">
                            <p class="text-[0.6rem] uppercase tracking-[0.2em] text-[color:var(--arena-muted)]">Ganador reportado</p>
                            <p class="mt-1 font-semibold text-white arena-body-text">
                                @if($match->report->claimed_winner_team === 'draw')
                                    Empate
                                @elseif($match->report->claimed_winner_team === 'team_a')
                                    Team A ({{ \App\Models\ArenaMatch::REALMS[$match->team_a_realm] ?? strtoupper((string) $match->team_a_realm) }})
                                @else
                                    Team B ({{ \App\Models\ArenaMatch::REALMS[$match->team_b_realm] ?? strtoupper((string) $match->team_b_realm) }})
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-{{ count($match->report->evidenceItems()) > 1 ? '2' : '1' }}">
                        @foreach($match->report->evidenceItems() as $evidence)
                            <a href="{{ $evidence['url'] }}" target="_blank" class="arena-btn-ghost justify-center">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                                {{ $evidence['label'] }}
                            </a>
                        @endforeach
                    </div>
                    @if($match->report->reporter_note)
                        <div class="arena-card p-4">
                            <p class="text-xs font-semibold text-[color:var(--arena-gold-soft)]">Nota del reporter</p>
                            <p class="mt-2 text-sm text-[color:var(--arena-text)] arena-body-text">{{ $match->report->reporter_note }}</p>
                        </div>
                    @endif
                    @if($match->report->rejection_note)
                        <div class="arena-card p-4">
                            <p class="text-xs font-semibold text-amber-300">Nota de rechazo rival</p>
                            <p class="mt-2 text-sm text-[color:var(--arena-text)] arena-body-text">{{ $match->report->rejection_note }}</p>
                        </div>
                    @endif
                    @if($match->report->admin_note)
                        <div class="arena-card p-4">
                            <p class="text-xs font-semibold text-emerald-300">Nota admin</p>
                            <p class="mt-2 text-sm text-[color:var(--arena-text)] arena-body-text">{{ $match->report->admin_note }}</p>
                        </div>
                    @endif
                    @if($match->report->reviewed_at || data_get($match->report->resolution_payload, 'original_claimed_winner_team'))
                        <div class="arena-card p-4">
                            <p class="text-xs font-semibold text-[color:var(--arena-gold-soft)]">Revision de moderacion</p>
                            @if(data_get($match->report->resolution_payload, 'original_claimed_winner_team') && data_get($match->report->resolution_payload, 'original_claimed_winner_team') !== $match->report->claimed_winner_team)
                                <p class="mt-2 text-sm text-[color:var(--arena-text)] arena-body-text">El ganador reportado fue corregido por moderacion antes del cierre.</p>
                            @endif
                            @if($match->report->reviewer)
                                <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">Revisado por {{ $match->report->reviewer->display_name ?? $match->report->reviewer->name ?? $match->report->reviewer->username ?? 'admin' }}{{ $match->report->reviewed_at ? ' � ' . $match->report->reviewed_at->diffForHumans() : '' }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            @else
                <div class="arena-card mt-5 p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                    <p>Sin reporte cargado.</p>
                </div>
            @endif
        </section>

        {{-- Results --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-4">
            <h2 class="text-2xl font-semibold text-white">Resultado persistido</h2>
            @if($match->results->isEmpty())
                <div class="arena-card mt-5 p-5 text-center text-[color:var(--arena-muted)] arena-body-text">
                    <p>Sin cambios en match_results.</p>
                </div>
            @else
                <div class="mt-5 space-y-3">
                    @foreach($match->results as $result)
                        <article class="arena-card p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-semibold text-white arena-body-text">{{ $result->player->character_name }}</h3>
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $result->result === 'win' ? 'bg-emerald-900/30 text-emerald-300' : 'bg-rose-900/30 text-rose-300' }}">
                                        {{ strtoupper($result->result) }}
                                    </span>
                                </div>
                                <span class="text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $result->reported_by_admin ? 'Admin' : 'Jugador' }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2 text-sm arena-body-text">
                                <span class="{{ $result->pl_change >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                    PL {{ $result->pl_change >= 0 ? '+' : '' }}{{ number_format((float) $result->pl_change, 1) }}
                                </span>
                                <span class="{{ $result->mmr_change >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                    MMR {{ $result->mmr_change >= 0 ? '+' : '' }}{{ $result->mmr_change }}
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- Admin actions --}}
    <section class="arena-panel p-6 arena-animate-in arena-stagger-5">
        <h2 class="text-2xl font-semibold text-white">Acciones admin</h2>
        <div class="mt-5 grid gap-4 xl:grid-cols-2">
            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" class="arena-card space-y-3 p-4">
                @csrf
                <input type="hidden" name="action" value="force_complete">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-900/30 text-emerald-300">&#10003;</span>
                    <h3 class="font-semibold text-white arena-body-text">Revisar reporte y aplicar resultado</h3>
                </div>
                <select name="winner_team" class="arena-select">
                    <option value="team_a" {{ $match->report?->claimed_winner_team === 'team_a' ? 'selected' : '' }}>Gana Team A ({{ \App\Models\ArenaMatch::REALMS[$match->team_a_realm] ?? strtoupper((string) $match->team_a_realm) }})</option>
                    <option value="team_b" {{ $match->report?->claimed_winner_team === 'team_b' ? 'selected' : '' }}>Gana Team B ({{ \App\Models\ArenaMatch::REALMS[$match->team_b_realm] ?? strtoupper((string) $match->team_b_realm) }})</option>
                    <option value="draw" {{ $match->report?->claimed_winner_team === 'draw' ? 'selected' : '' }}>Empate / cierre sin ganador</option>
                </select>
                <textarea name="note" rows="2" class="arena-textarea" placeholder="Nota de moderacion o correccion del reporte"></textarea>
                <button type="submit" class="arena-btn-safe w-full">Corregir y aplicar resultado</button>
            </form>

            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" class="arena-card space-y-3 p-4">
                @csrf
                <input type="hidden" name="action" value="dispute">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-900/30 text-amber-300">⚠</span>
                    <h3 class="font-semibold text-white arena-body-text">Marcar disputa</h3>
                </div>
                <textarea name="note" rows="2" class="arena-textarea" placeholder="Motivo de disputa"></textarea>
                <button type="submit" class="arena-btn-warning w-full">Enviar a disputa</button>
            </form>

            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" class="arena-card space-y-3 p-4">
                @csrf
                <input type="hidden" name="action" value="abandonment_walkover">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-orange-900/30 text-orange-300">🚪</span>
                    <h3 class="font-semibold text-white arena-body-text">Abandono con derrota</h3>
                </div>
                <select name="player_id" class="arena-select">
                    @foreach($match->getAllPlayers() as $player)
                        <option value="{{ $player['player_id'] }}">{{ $player['character_name'] }}</option>
                    @endforeach
                </select>
                <textarea name="note" rows="2" class="arena-textarea" placeholder="Nota de abandono"></textarea>
                <button type="submit" class="arena-btn-warning w-full">Aplicar walkover</button>
            </form>

            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" class="arena-card space-y-3 p-4">
                @csrf
                <input type="hidden" name="action" value="support_infraction">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-900/30 text-rose-300">⛔</span>
                    <h3 class="font-semibold text-white arena-body-text">Infracción doble soporte</h3>
                </div>
                <select name="player_id" class="arena-select">
                    @foreach($match->getAllPlayers() as $player)
                        <option value="{{ $player['player_id'] }}">{{ $player['character_name'] }}</option>
                    @endforeach
                </select>
                <textarea name="note" rows="2" class="arena-textarea" placeholder="Evidencia de la infracción"></textarea>
                <button type="submit" class="arena-btn-danger w-full">Procesar infracción</button>
            </form>

            <form method="POST" action="{{ route('admin.matches.resolve', $match) }}" class="arena-card space-y-3 p-4 xl:col-span-2">
                @csrf
                <input type="hidden" name="action" value="void">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 text-[color:var(--arena-muted)]">✕</span>
                    <h3 class="font-semibold text-white arena-body-text">Marcar void</h3>
                </div>
                <textarea name="note" rows="2" class="arena-textarea" placeholder="Motivo de anulación"></textarea>
                <button type="submit" class="arena-btn-danger-ghost w-full" onclick="return confirm('¿Estás seguro de anular este match?')">Marcar void</button>
            </form>
        </div>
    </section>
</div>

{{-- ── ADMIN MAP MODAL ── --}}
<div id="modal-admin-zone-map" class="fixed inset-0 z-50 items-center justify-center" style="display:none" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" data-modal-close="modal-admin-zone-map"></div>
    <div class="relative mx-4 w-full max-w-3xl rounded-2xl border border-[color:var(--arena-line-strong)] bg-[linear-gradient(180deg,rgba(40,28,20,0.98),rgba(14,10,8,0.99))] p-6 shadow-[0_25px_60px_rgba(0,0,0,0.5)]" style="animation: arenaModalIn 0.2s ease-out">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-[color:var(--arena-gold)] font-['Cinzel']">Auditoría de Zona</p>
                <h3 class="mt-1 text-xl font-semibold text-white">{{ $match->zone_name }}</h3>
            </div>
            <button type="button" class="shrink-0 rounded-full p-1.5 text-[color:var(--arena-muted)] transition-colors hover:bg-white/10 hover:text-white" data-modal-close="modal-admin-zone-map" aria-label="Cerrar">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <x-arena-zone-map :zone-key="$match->zone_key" height="450px" />
    </div>
</div>
@endsection
