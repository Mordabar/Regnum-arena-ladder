{{-- La cabecera del lobby, aparte igual que el panel.

     Dice en que punto esta el jugador (el titulo, la explicacion y el
     recorrido de cuatro pasos), asi que cuando el sondeo cambia el panel
     tambien tiene que cambiar esto: si no, el panel ensena el lobby mientras
     el titulo sigue diciendo "buscando combate". --}}
<div data-console-head>
    <section class="arena-panel-strong mb-5 p-6 md:p-7 arena-animate-in">
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="arena-kicker">Arena {{ $arenaMode }}</p>
                    @if($shouldAutoRefresh)
                        <span id="statePollingActive" class="arena-chip text-xs bg-black/40 border border-emerald-500/30 text-emerald-300 px-2 py-1">
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-400 mr-1 animate-pulse"></span>
                            En vivo
                        </span>
                    @endif
                </div>
                <h1 class="arena-console-title mt-2 text-3xl font-bold text-[color:var(--arena-gold-soft)] md:text-4xl">
                    @if($matchIsPendingAcceptance)
                        Cruce encontrado
                    @elseif($currentMatch)
                        Tu combate
                    @elseif($currentQueue)
                        Buscando combate…
                    @else
                        <span class="arena-hide-mobile">Bienvenido, </span>{{ auth()->user()->discord_username }}
                    @endif
                </h1>
                <p class="arena-console-lede mt-2 max-w-2xl text-[color:var(--arena-sand)] arena-body-text">
                    @if($matchIsPendingAcceptance)
                        Confirma abajo antes de que se agote el reloj. Si alguien no acepta, el cruce se cancela.
                    @elseif($currentMatch)
                        Sigue el estado del enfrentamiento y reporta el resultado al terminar.
                    @elseif($currentQueue)
                        Espera aquí. En cuanto haya rival te avisamos y el combate aparece en esta misma pantalla.
                    @else
                        Elige un guerrero y entra a la arena. Todo pasa en esta pantalla.
                    @endif
                </p>

                @if(!$modesAreOpen)
                    <p class="mt-4 rounded-2xl border border-amber-700/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-200 arena-body-text">
                        Las colas están cerradas por el momento. Vuelve más tarde.
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('matches.index') }}" class="arena-btn-secondary px-4 py-2">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>
                    Mis combates
                </a>
                <a href="{{ route('ladder.index') }}" class="arena-btn-ghost px-4 py-2">Ladder</a>
            </div>
        </div>

        <div class="mt-6">
            <x-arena-stepper :steps="['Registra', 'Elige modo', 'Espera cruce', 'Pelea y reporta']" :current="$stepperCurrent" />
        </div>
    </section>
</div>
