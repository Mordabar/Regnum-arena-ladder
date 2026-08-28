@extends('layouts.arena')

@section('title', 'Regnum Arena Ladder — Conquest PvP')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10">
    {{-- ── HERO ── --}}
    <section class="arena-panel-strong mb-10 overflow-hidden p-8 md:p-12 relative">
        {{-- Decorative realm glows --}}
        <div class="absolute -top-20 -left-20 w-64 h-64 rounded-full bg-[radial-gradient(circle,rgba(211,100,47,0.15),transparent_70%)] pointer-events-none"></div>
        <div class="absolute -top-20 -right-20 w-64 h-64 rounded-full bg-[radial-gradient(circle,rgba(121,181,214,0.12),transparent_70%)] pointer-events-none"></div>
        <div class="absolute -bottom-20 left-1/2 -translate-x-1/2 w-80 h-64 rounded-full bg-[radial-gradient(circle,rgba(142,179,74,0.1),transparent_70%)] pointer-events-none"></div>

        <div class="relative grid items-center gap-10 lg:grid-cols-[1.08fr,0.92fr]">
            <div class="arena-animate-in">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1">
                        <x-arena-realm-icon realm="ignis" size="sm" />
                        <x-arena-realm-icon realm="alsius" size="sm" />
                        <x-arena-realm-icon realm="syrtis" size="sm" />
                    </div>
                    <p class="arena-kicker">Conquest PvP</p>
                </div>
                <h1 class="mt-4 text-5xl font-bold text-[color:var(--arena-gold-soft)] md:text-6xl leading-tight">
                    {{ \App\Models\AppSetting::getValue('season_name', 'Alpha Season') }}
                </h1>
                <p class="mt-4 max-w-2xl text-lg text-[color:var(--arena-sand)] arena-body-text">
                    {{ \App\Models\AppSetting::getValue('home_tagline', 'Conquest PvP por reino y subclase') }}
                </p>
                <p class="mt-3 max-w-2xl text-[color:var(--arena-muted)] arena-body-text">
                    {{ \App\Models\AppSetting::getValue('rules_excerpt', 'Random y premade, anonimato rival, reporte con capturas y ladder automático por PL/MMR.') }}
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    @auth
                        <a href="{{ route('lobby') }}" class="arena-btn">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
                            Ir al lobby
                        </a>
                        <a href="{{ route('queue.index') }}" class="arena-btn-secondary">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                            Buscar combate
                        </a>
                    @else
                        <a href="{{ route('auth.discord') }}" class="arena-btn-secondary">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                            Entrar con Discord
                        </a>
                    @endauth
                    <a href="{{ route('ladder.index') }}" class="arena-btn-ghost">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a1 1 0 000 2c5.523 0 10 4.477 10 10a1 1 0 102 0C17 8.373 11.627 3 5 3z"/><path d="M4 9a1 1 0 011-1 7 7 0 017 7 1 1 0 11-2 0 5 5 0 00-5-5 1 1 0 01-1-1zM3 15a2 2 0 114 0 2 2 0 01-4 0z"/></svg>
                        Ver ladder
                    </a>
                </div>
            </div>

            <div class="grid gap-4 arena-animate-in arena-stagger-2">
                <div class="flex justify-center lg:justify-end">
                    <x-arena-brand class="rounded-[2rem] border border-[color:var(--arena-line)] bg-[linear-gradient(180deg,rgba(47,34,24,0.74),rgba(16,11,8,0.9))] px-6 py-5 shadow-[0_20px_45px_rgba(0,0,0,0.26)]" />
                </div>

                {{-- Stepper: How it works --}}
                <x-arena-stepper
                    :steps="['Registra', 'Entra a cola', 'Pelea', 'Reporta']"
                    :current="1"
                    class="mt-2"
                />
            </div>
        </div>
    </section>

    {{-- ── HOW IT WORKS ── --}}
    <section class="mb-10 grid gap-5 md:grid-cols-3">
        <article class="arena-card arena-card-interactive arena-animate-in arena-stagger-1 p-6">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[rgba(216,177,92,0.12)] text-[color:var(--arena-gold)]">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>
            </div>
            <p class="arena-kicker mt-4">Paso 1</p>
            <h2 class="mt-2 text-xl font-semibold text-white">Registra tu guerrero</h2>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Elige personaje, subclase y reino. Tu progreso en la arena nace desde aquí.
            </p>
        </article>

        <article class="arena-card arena-card-interactive arena-animate-in arena-stagger-2 p-6">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[rgba(121,181,214,0.12)] text-[color:var(--arena-ice)]">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
            </div>
            <p class="arena-kicker mt-4">Paso 2</p>
            <h2 class="mt-2 text-xl font-semibold text-white">Entra a cola y recibe zona</h2>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Random o premade. El sistema arma el cruce, asigna zona y mantiene oculto al rival.
            </p>
        </article>

        <article class="arena-card arena-card-interactive arena-animate-in arena-stagger-3 p-6">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[rgba(142,179,74,0.12)] text-[color:var(--arena-forest)]">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            </div>
            <p class="arena-kicker mt-4">Paso 3</p>
            <h2 class="mt-2 text-xl font-semibold text-white">Reporta y cierra el match</h2>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Sube 2 capturas, el rival confirma y el ladder actualiza PL y MMR automáticamente.
            </p>
        </article>
    </section>

    {{-- ── FEATURES ── --}}
    <section class="grid gap-6 md:grid-cols-3">
        <article class="arena-panel arena-animate-in arena-stagger-4 p-6">
            <div class="flex items-center gap-3">
                <svg class="h-6 w-6 text-[color:var(--arena-gold)]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"/><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"/></svg>
                <h2 class="text-xl font-semibold text-white">Anonimato rival</h2>
            </div>
            <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Solo ves el reino rival hasta que el match se cierre o entre en disputa. Sin ventajas previas.
            </p>
        </article>
        <article class="arena-panel arena-animate-in arena-stagger-5 p-6">
            <div class="flex items-center gap-3">
                <svg class="h-6 w-6 text-[color:var(--arena-gold)]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V7.414A2 2 0 0015.414 6L12 2.586A2 2 0 0010.586 2H6zm5 6a1 1 0 10-2 0v3.586l-1.293-1.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V8z" clip-rule="evenodd"/></svg>
                <h2 class="text-xl font-semibold text-white">Scoring justo</h2>
            </div>
            <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Ladder público por PL y emparejamiento por MMR oculto. Caps, underdog bonus y ajuste entre random y premade.
            </p>
        </article>
        <article class="arena-panel arena-animate-in arena-stagger-6 p-6">
            <div class="flex items-center gap-3">
                <svg class="h-6 w-6 text-[color:var(--arena-gold)]" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <h2 class="text-xl font-semibold text-white">Anti-abuso integrado</h2>
            </div>
            <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Protección contra farm, abandono con bloqueo 12h, verificación de rol conjurador y repetición de rivales.
            </p>
        </article>
    </section>
</div>
@endsection
