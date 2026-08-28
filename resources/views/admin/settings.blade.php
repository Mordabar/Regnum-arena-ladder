@extends('layouts.arena')

@section('title', 'Admin Settings - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <x-arena-breadcrumbs :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Configuración'],
    ]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 arena-animate-in">
        <p class="arena-kicker">Admin</p>
        <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Configuración</h1>
        <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">Branding, ventanas de runtime, balance de cola y sanciones.</p>
    </section>

    <div class="grid gap-6 lg:grid-cols-[1fr,0.85fr]">
        <section class="arena-panel p-6 arena-animate-in arena-stagger-1">
            <h2 class="text-2xl font-semibold text-white">Ajustes editables</h2>
            <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-5 space-y-5">
                @csrf

                {{-- Modalidades de arena --}}
                <details class="arena-card group" open>
                    <summary class="cursor-pointer px-5 py-4 flex items-center justify-between">
                        <h3 class="font-semibold text-white arena-body-text">⚔️ Modalidades activas</h3>
                        <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="px-5 pb-5 space-y-4">
                        <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                            Cada modalidad se enciende o apaga por separado y pueden convivir. El ladder es uno solo:
                            un match de 2v2 y uno de 3v3 suman los mismos PL y MMR a la misma tabla.
                        </p>

                        @foreach(['2v2' => ['Arena 2v2', 'Equipos de 2 jugadores'], '3v3' => ['Arena 3v3', 'Equipos de 3 jugadores']] as $modeKey => $modeInfo)
                            <label class="flex items-center justify-between gap-4 rounded-2xl border border-[color:var(--arena-line)] bg-black/20 px-4 py-3 cursor-pointer">
                                <span>
                                    <span class="block font-semibold text-white">{{ $modeInfo[0] }}</span>
                                    <span class="mt-1 block text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $modeInfo[1] }}</span>
                                </span>
                                {{-- El hidden garantiza que un checkbox destildado llegue como 0 --}}
                                <input type="hidden" name="mode_{{ $modeKey }}_enabled" value="0">
                                <input type="checkbox"
                                       name="mode_{{ $modeKey }}_enabled"
                                       value="1"
                                       class="h-5 w-5 accent-amber-500"
                                       @checked($settings['mode_' . $modeKey . '_enabled'])>
                            </label>
                        @endforeach

                        @if(!$settings['mode_2v2_enabled'] && !$settings['mode_3v3_enabled'])
                            <p class="rounded-2xl border border-amber-700/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-200 arena-body-text">
                                ⚠️ Con las dos modalidades apagadas nadie puede entrar a cola. Los matches ya en curso
                                siguen su flujo normal hasta reportarse.
                            </p>
                        @endif
                    </div>
                </details>

                {{-- Branding --}}
                <details class="arena-card group" open>
                    <summary class="cursor-pointer px-5 py-4 flex items-center justify-between">
                        <h3 class="font-semibold text-white arena-body-text">🏷️ Branding</h3>
                        <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="px-5 pb-5 space-y-4">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Nombre de temporada</span>
                            <input type="text" name="season_name" value="{{ $settings['season_name'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Tagline home</span>
                            <input type="text" name="home_tagline" value="{{ $settings['home_tagline'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Resumen de reglas</span>
                            <textarea name="rules_excerpt" rows="3" class="arena-textarea">{{ $settings['rules_excerpt'] }}</textarea>
                        </label>
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Contacto soporte</span>
                                <input type="text" name="support_contact" value="{{ $settings['support_contact'] }}" class="arena-field">
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Invitación Discord</span>
                                <input type="url" name="discord_invite_url" value="{{ $settings['discord_invite_url'] }}" class="arena-field">
                            </label>
                        </div>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Label server Discord</span>
                            <input type="text" name="discord_server_label" value="{{ $settings['discord_server_label'] }}" class="arena-field">
                        </label>
                    </div>
                </details>

                {{-- Ventanas de tiempo --}}
                <details class="arena-card group" open>
                    <summary class="cursor-pointer px-5 py-4 flex items-center justify-between">
                        <h3 class="font-semibold text-white arena-body-text">⏱️ Ventanas de tiempo</h3>
                        <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="px-5 pb-5 grid gap-4 md:grid-cols-3">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Minutos aceptar</span>
                            <input type="number" min="1" max="30" name="accept_window_minutes" value="{{ $settings['accept_window_minutes'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Minutos cacería</span>
                            <input type="number" min="5" max="120" name="hunt_window_minutes" value="{{ $settings['hunt_window_minutes'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Minutos confirmar reporte</span>
                            <input type="number" min="1" max="60" name="report_confirmation_window_minutes" value="{{ $settings['report_confirmation_window_minutes'] }}" class="arena-field">
                        </label>
                    </div>
                </details>

                {{-- Balance de cola --}}
                <details class="arena-card group">
                    <summary class="cursor-pointer px-5 py-4 flex items-center justify-between">
                        <h3 class="font-semibold text-white arena-body-text">⚖️ Balance de scoring</h3>
                        <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="px-5 pb-5 space-y-4">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Límite premade por dupla/día</span>
                            <input type="number" min="1" max="10" name="premade_daily_limit" value="{{ $settings['premade_daily_limit'] }}" class="arena-field">
                            <p class="mt-1 text-xs text-[color:var(--arena-muted)] arena-body-text">{{ $settings['premade_daily_limit'] }} matches/día por dupla exacta.</p>
                        </label>
                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Bonus PL random vs premade %</span>
                                <input type="number" min="0" max="50" step="0.1" name="random_vs_premade_pl_bonus_pct" value="{{ $settings['random_vs_premade_pl_bonus_pct'] }}" class="arena-field">
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Bonus MMR random vs premade %</span>
                                <input type="number" min="0" max="50" step="0.1" name="random_vs_premade_mmr_bonus_pct" value="{{ $settings['random_vs_premade_mmr_bonus_pct'] }}" class="arena-field">
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Penalidad PL premade gana a random %</span>
                                <input type="number" min="0" max="50" step="0.1" name="premade_vs_random_pl_win_penalty_pct" value="{{ $settings['premade_vs_random_pl_win_penalty_pct'] }}" class="arena-field">
                            </label>
                            <label class="block">
                                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Penalidad MMR premade gana a random %</span>
                                <input type="number" min="0" max="50" step="0.1" name="premade_vs_random_mmr_win_penalty_pct" value="{{ $settings['premade_vs_random_mmr_win_penalty_pct'] }}" class="arena-field">
                            </label>
                        </div>
                    </div>
                </details>

                {{-- Sanciones --}}
                <details class="arena-card group">
                    <summary class="cursor-pointer px-5 py-4 flex items-center justify-between">
                        <h3 class="font-semibold text-white arena-body-text">🛡️ Sanciones</h3>
                        <svg class="h-4 w-4 text-[color:var(--arena-muted)] transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="px-5 pb-5 grid gap-4 md:grid-cols-3">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Horas lock abandono</span>
                            <input type="number" min="1" max="168" name="abandonment_lock_hours" value="{{ $settings['abandonment_lock_hours'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Horas lock soporte</span>
                            <input type="number" min="1" max="168" name="support_infraction_lock_hours" value="{{ $settings['support_infraction_lock_hours'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Cap máximo lock (h)</span>
                            <input type="number" min="1" max="336" name="penalty_max_lock_hours" value="{{ $settings['penalty_max_lock_hours'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Trust penalty abandono</span>
                            <input type="number" min="1" max="100" name="abandonment_trust_penalty" value="{{ $settings['abandonment_trust_penalty'] }}" class="arena-field">
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Trust penalty soporte</span>
                            <input type="number" min="1" max="100" name="support_infraction_trust_penalty" value="{{ $settings['support_infraction_trust_penalty'] }}" class="arena-field">
                        </label>
                    </div>
                </details>

                <button type="submit" class="arena-btn w-full">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Guardar configuración
                </button>
            </form>
        </section>

        {{-- Discord status --}}
        <section class="arena-panel p-6 arena-animate-in arena-stagger-2">
            <h2 class="text-2xl font-semibold text-white">Estado Discord</h2>
            <div class="mt-5 space-y-3">
                <div class="arena-card p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">Bot token</p>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $discordConfig['bot_token_configured'] ? 'bg-emerald-900/30 text-emerald-300' : 'bg-rose-900/30 text-rose-300' }}">
                            {{ $discordConfig['bot_token_configured'] ? '✓ Configurado' : '✕ No configurado' }}
                        </span>
                    </div>
                </div>
                <div class="arena-card p-4">
                    <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">Guild ID</p>
                    <p class="mt-1 font-mono text-white arena-body-text">{{ $discordConfig['guild_id'] ?: 'Sin valor en ENV' }}</p>
                </div>
                <div class="arena-card p-4">
                    <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">Alerts channel ID</p>
                    <p class="mt-1 font-mono text-white arena-body-text">{{ $discordConfig['alerts_channel_id'] ?: 'Sin valor en ENV' }}</p>
                </div>
                <div class="arena-card p-4">
                    <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">Admin Discord IDs</p>
                    <p class="mt-1 text-white arena-body-text">{{ implode(', ', $discordConfig['admin_ids']) ?: 'Sin valores en ENV' }}</p>
                </div>
                <div class="arena-card p-4 text-xs text-[color:var(--arena-muted)] arena-body-text">
                    La conexión real del bot depende de las variables del host. Esta pantalla valida la configuración base.
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
