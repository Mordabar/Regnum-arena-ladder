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

        <div class="arena-hero relative grid items-center gap-10 lg:grid-cols-[1.08fr,0.92fr]">
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
                    {{ \App\Models\AppSetting::getValue('home_tagline', 'Conquest PvP 1v1, 2v2 y 3v3 en la Zona de Guerra') }}
                </p>
                <p class="mt-3 max-w-2xl text-[color:var(--arena-muted)] arena-body-text">
                    {{ \App\Models\AppSetting::getValue('rules_excerpt', 'Busca contrincante, quedad en el punto marcado, pelead y reporta el resultado. Cada combate te sube en el ladder: se juega por los premios de la temporada y por quedarse en el Salon de la Fama, donde solo aguantan los mejores.') }}
                </p>

                {{-- Los cuatro en una fila: el cuarto saltaba a una
                     segunda linea y eso son cuarenta pixeles mas de alto en la
                     primera pantalla. Los secundarios van algo mas apretados
                     -clase `is-fila`- para que quepan. --}}
                <div class="mt-8 flex flex-wrap gap-2.5 arena-hero-acciones">
                    @auth
                        {{-- El lobby y la arena son la misma pantalla: dos
                             botones al mismo sitio solo hacian dudar. --}}
                        <a href="{{ route('lobby') }}" class="arena-btn">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                            Entrar al lobby
                        </a>
                    @else
                        <a href="{{ route('auth.discord') }}" class="arena-btn-secondary">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                            Entrar con Discord
                        </a>
                    @endauth
                    <a href="{{ route('hall-of-fame') }}" class="arena-btn-ghost">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1l2.39 4.84 5.34.78-3.86 3.77.91 5.32L10 13.2l-4.78 2.51.91-5.32L2.27 6.62l5.34-.78L10 1z"/></svg>
                        Salon de la Fama
                    </a>
                    <a href="{{ route('ladder.index') }}" class="arena-btn-ghost">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5 3a1 1 0 000 2c5.523 0 10 4.477 10 10a1 1 0 102 0C17 8.373 11.627 3 5 3z"/><path d="M4 9a1 1 0 011-1 7 7 0 017 7 1 1 0 11-2 0 5 5 0 00-5-5 1 1 0 01-1-1zM3 15a2 2 0 114 0 2 2 0 01-4 0z"/></svg>
                        Ver ladder
                    </a>
                    {{-- La guia, aqui arriba con los demas. Estaba solo en el
                         pie y en un cuadro al final de la portada: quien llega
                         sin conocer el sitio no baja a buscarla.

                         Va en la misma fila y se envuelve sola, asi que no
                         añade altura mientras quepa. --}}
                    <a href="{{ route('guia') }}" class="arena-btn-ghost">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                        Como funciona
                    </a>
                </div>
            </div>

            <div class="arena-hero-side grid gap-4 arena-animate-in arena-stagger-2">
                @if($premios->activos())
                    <x-arena-podium :podio="$podio" :premios="$premios" />
                @else
                    <div class="flex justify-center lg:justify-end">
                        <x-arena-brand class="rounded-[2rem] border border-[color:var(--arena-line)] bg-[linear-gradient(180deg,rgba(47,34,24,0.74),rgba(16,11,8,0.9))] px-6 py-5 shadow-[0_20px_45px_rgba(0,0,0,0.26)]" />
                    </div>
                @endif

                {{-- Stepper: How it works --}}
                <x-arena-stepper
                    :steps="['Registra', 'Elige modo', 'Espera cruce', 'Pelea y reporta']"
                    :current="1"
                    class="mt-2"
                />
            </div>
        </div>
    </section>

    {{-- Los pasos y las reglas se fueron a /como-funciona.
         Aqui abajo eran seis cuadros de texto entre el podio y el pie: quien
         llega a la portada viene a ver el juego y lo que hay en juego, no un
         manual. Quien quiera el manual, tiene la puerta. --}}
    <section class="arena-panel-strong p-6 md:p-8 text-center arena-animate-in">
        <p class="arena-kicker">Primera vez aqui</p>
        <h2 class="mt-3 text-2xl font-semibold text-[color:var(--arena-gold-soft)]">Como funciona el Arena Ladder</h2>
        <p class="mx-auto mt-2 max-w-2xl text-sm text-[color:var(--arena-muted)] arena-body-text">
            Como se entra a cola, que se ve del rival, como se puntua y que pasa si alguien no
            aparece. En dos minutos de lectura.
        </p>
        <a href="{{ route('guia') }}" class="arena-btn-ghost mt-6">Leer la guia</a>
    </section>
</div>
@endsection
