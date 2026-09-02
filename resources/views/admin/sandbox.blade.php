@extends('layouts.admin')

@section('title', 'Entorno de pruebas')
@section('page-title', 'Entorno de pruebas')
@section('page-subtitle', 'Bots para ensayar el flujo completo sin esperar a que haya gente en cola')

@section('page-actions')
    <a href="{{ route('lobby') }}" class="ap-btn ap-btn-sm ap-btn-quiet" target="_blank" rel="noopener">Abrir la cola real</a>
@endsection

@section('content')
@php
    $summary = $sandbox['summary'];
    $playersByRealm = $sandbox['playersByRealm'];
    $activeQueueByPlayer = $sandbox['activeQueueByPlayer'];
    $pendingMatches = $sandbox['pendingMatches'];
    $inProgressMatches = $sandbox['inProgressMatches'];
    $recentMatches = $sandbox['recentMatches'];
    $enabledModes = \App\Support\ArenaMode::enabled();

    // Se arma el texto en PHP en vez de con directivas Blade sueltas: una
    // directiva pegada a una palabra (rival@if) no se compila y deja un @else
    // huerfano que rompe la pagina entera al renderizar.
    $needsByMode = collect($enabledModes)
        ->map(function (string $mode) {
            $size = \App\Support\ArenaMode::teamSize($mode);

            return $mode . ': ' . ($size - 1) . ' de tu reino y ' . $size . ' del rival';
        })
        ->implode(' · ');
@endphp

{{-- Aviso primero: estos bots escriben en las mismas tablas que produccion. --}}
<div class="ap-flash ap-rise" style="border-color: color-mix(in srgb, var(--ap-warn) 35%, transparent); background: var(--ap-warn-dim); color: var(--ap-warn)">
    <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
    <span>
        Los bots juegan sobre las tablas reales. Si resuelves una partida donde participa un
        personaje tuyo de verdad, ese personaje gana o pierde puntos como en produccion.
        Usa un personaje dedicado a pruebas.
    </span>
</div>

@if(!$sandbox['matchesSchemaReady'])
    <div class="ap-flash ap-flash-danger" role="alert">
        <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
        <span>Faltan columnas en la tabla de enfrentamientos. Ejecuta las migraciones antes de usar este entorno.</span>
    </div>
@endif

@if(empty($enabledModes))
    <div class="ap-flash ap-flash-danger" role="alert">
        <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
        <span>
            No hay ninguna modalidad abierta, asi que nadie puede entrar en cola ni aqui ni en el sitio.
            <a href="{{ route('admin.settings') }}" style="color: inherit; text-decoration: underline">Abrir 2v2 o 3v3</a>.
        </span>
    </div>
@endif

{{-- Estado del laboratorio --}}
<section class="ap-rise ap-delay-1 mb-5">
    <x-admin.section-head title="Estado del laboratorio" icon="gauge"
                            note="Solo cuenta bots, no jugadores reales." />
    <div class="grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
        @foreach([
            ['Bots creados', $summary['players'], 'personajes de prueba'],
            ['Libres', $summary['idle_players'], 'sin cola ni partida'],
            ['En cola', $summary['waiting'], 'buscando rival'],
            ['Emparejados', $summary['matched'] + $summary['accepted'], 'con partida asignada'],
            ['Sin aceptar', $summary['pending_matches'], 'esperando el si de todos'],
            ['En juego', $summary['in_progress_matches'], 'partidas abiertas'],
        ] as [$label, $value, $note])
            <div class="ap-metric">
                <span class="ap-metric-label">{{ $label }}</span>
                <span class="ap-metric-value ap-num">{{ $value }}</span>
                <span class="ap-metric-note">{{ $note }}</span>
            </div>
        @endforeach
    </div>
</section>

<div class="grid gap-4 xl:grid-cols-2 items-start">

    {{-- Paso 1 --}}
    <section class="ap-card ap-rise ap-delay-2 p-4">
        <div class="ap-section-head">
            <div class="ap-section-lead">
                <span class="ap-section-mark"><x-admin.icon name="bot" class="h-4 w-4" /></span>
                <div class="min-w-0">
                    <h2 class="ap-section-title"><span class="ap-step">1</span> Crear el roster de bots</h2>
                    <p class="ap-section-note">Cuantos personajes de prueba quieres en cada reino.</p>
                </div>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.testing.seed') }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            @foreach(['ignis' => 'Ignis', 'syrtis' => 'Syrtis', 'alsius' => 'Alsius'] as $realmKey => $realmLabel)
                <div class="ap-field">
                    <label class="ap-label" for="seed-{{ $realmKey }}">{{ $realmLabel }}</label>
                    <input type="number" id="seed-{{ $realmKey }}" name="{{ $realmKey }}_count" min="0" max="60"
                           value="{{ old($realmKey . '_count', $playersByRealm->get($realmKey, collect())->count()) }}" class="ap-input">
                </div>
            @endforeach
            <div class="ap-field justify-end">
                <button type="submit" class="ap-btn ap-btn-primary ap-btn-block">Generar</button>
            </div>
            <label class="flex items-center gap-2 sm:col-span-4" style="font-size: 12.5px; color: var(--ap-text-muted)">
                <input type="checkbox" name="replace_existing" value="1" checked class="ap-checkbox">
                Borrar los bots que ya existan y empezar de cero
            </label>
        </form>
    </section>

    {{-- Paso 2 --}}
    <section class="ap-card ap-rise ap-delay-3 p-4">
        <div class="ap-section-head">
            <div class="ap-section-lead">
                <span class="ap-section-mark"><x-admin.icon name="users" class="h-4 w-4" /></span>
                <div class="min-w-0">
                <h2 class="ap-section-title"><span class="ap-step">2</span> Meter bots en la cola</h2>
                <p class="ap-section-note">
                    @if($needsByMode !== '')
                        Para que te toque a ti necesitas {{ $needsByMode }}.
                    @else
                        Abre una modalidad antes de encolar a nadie.
                    @endif
                </p>
                </div>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.testing.enqueue-realm') }}" class="grid gap-3 sm:grid-cols-4">
            @csrf
            <div class="ap-field">
                <label class="ap-label" for="eq-realm">Reino</label>
                <select name="realm" id="eq-realm" class="ap-select">
                    <option value="ignis">Ignis</option>
                    <option value="syrtis">Syrtis</option>
                    <option value="alsius">Alsius</option>
                </select>
            </div>
            <div class="ap-field">
                <label class="ap-label" for="eq-count">Cuantos</label>
                <input type="number" id="eq-count" name="count" min="1" max="60" value="2" class="ap-input">
            </div>
            <div class="ap-field">
                <label class="ap-label" for="eq-mode">Modalidad</label>
                <select name="arena_mode" id="eq-mode" class="ap-select">
                    @foreach($enabledModes as $sandboxMode)
                        <option value="{{ $sandboxMode }}">{{ $sandboxMode }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ap-field justify-end">
                <button type="submit" class="ap-btn ap-btn-primary ap-btn-block" @disabled(empty($enabledModes))>Encolar</button>
            </div>
        </form>
    </section>

    {{-- Paso 3 --}}
    <section class="ap-card ap-rise ap-delay-4 p-4 xl:col-span-2">
        <div class="ap-section-head">
            <div class="ap-section-lead">
                <span class="ap-section-mark"><x-admin.icon name="play" class="h-4 w-4" /></span>
                <div class="min-w-0">
                    <h2 class="ap-section-title"><span class="ap-step">3</span> Empujar el flujo</h2>
                    <p class="ap-section-note">Estos botones hacen a mano lo que en produccion hace el reloj automatico.</p>
                </div>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div class="ap-actions-group">
                <p class="ap-actions-title">Avanzar la partida</p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.testing.process') }}">
                        @csrf
                        <button type="submit" class="ap-btn ap-btn-sm">Emparejar la cola</button>
                    </form>
                    <form method="POST" action="{{ route('admin.testing.accept') }}">
                        @csrf
                        <button type="submit" class="ap-btn ap-btn-sm">Aceptar por los bots</button>
                    </form>
                    <form method="POST" action="{{ route('admin.testing.resolve-all') }}">
                        @csrf
                        <button type="submit" class="ap-btn ap-btn-sm">Cerrar las de solo bots</button>
                    </form>
                </div>
            </div>

            <div class="ap-actions-group">
                <p class="ap-actions-title">Probar grupos (party)</p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.testing.invite-me') }}" class="flex gap-2">
                        @csrf
                        <select name="arena_mode" class="ap-select ap-select-sm" aria-label="Modalidad de la invitacion">
                            @foreach($enabledModes as $sandboxMode)
                                <option value="{{ $sandboxMode }}">{{ $sandboxMode }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="ap-btn ap-btn-sm" @disabled(empty($enabledModes))>Que un bot me invite</button>
                    </form>
                    <form method="POST" action="{{ route('admin.testing.accept-parties') }}">
                        @csrf
                        <button type="submit" class="ap-btn ap-btn-sm">Aceptar invitaciones</button>
                    </form>
                </div>
            </div>

            <div class="ap-actions-group ap-actions-danger">
                <p class="ap-actions-title" style="color: var(--ap-danger)">Limpiar</p>
                <p class="ap-section-note" style="margin: 0 0 8px">
                    Las dos opciones borran los enfrentamientos de prueba, tambien los que
                    jugaste con tu propio personaje, y le devuelven los puntos que ganó o
                    perdió en ellos.
                </p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.testing.reset') }}"
                          data-ap-confirm="Se borran las partidas y las colas de prueba, y los bots vuelven a cero. Los bots siguen existiendo. ¿Seguir?">
                        @csrf
                        <button type="submit" class="ap-btn ap-btn-sm">
                            <x-admin.icon name="refresh" class="h-4 w-4" />
                            Vaciar el estado
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.testing.destroy') }}"
                          data-ap-confirm="Se borra TODO el rastro de las pruebas: bots, sus cuentas, sus partidas, los reportes y las capturas subidas. A los personajes reales se les devuelven los puntos de esas partidas. ¿Seguir?">
                        @csrf
                        <button type="submit" class="ap-btn ap-btn-sm ap-btn-danger">
                            <x-admin.icon name="trash" class="h-4 w-4" />
                            Borrar todo el rastro
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- Partidas del laboratorio --}}
    <section class="ap-card ap-rise p-4">
        <div class="ap-section-head">
            <span class="ap-section-lead">
                <span class="ap-section-mark ap-section-mark-warn"><x-admin.icon name="clock" class="h-4 w-4" /></span>
                <h2 class="ap-section-title">Esperando aceptacion</h2>
            </span>
            <span class="ap-badge ap-badge-neutral ap-num">{{ $pendingMatches->count() }}</span>
        </div>
        @forelse($pendingMatches as $match)
            <div class="ap-list-row" style="border-color: var(--ap-line)">
                <div class="ap-list-main">
                    <div class="ap-list-title">{{ $match->match_code }}</div>
                    <div class="ap-list-meta">
                        <x-admin.realm :realm="$match->team_a_realm" /> vs <x-admin.realm :realm="$match->team_b_realm" />
                        · {{ $match->zone_name }}
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.testing.accept') }}">
                    @csrf
                    <input type="hidden" name="match_id" value="{{ $match->id }}">
                    <button type="submit" class="ap-btn ap-btn-sm">Aceptar</button>
                </form>
            </div>
        @empty
            <div class="ap-empty"><p class="m-0">Ninguna partida esperando aceptacion.</p></div>
        @endforelse
    </section>

    <section class="ap-card ap-rise p-4">
        <div class="ap-section-head">
            <span class="ap-section-lead">
                <span class="ap-section-mark ap-section-mark-ok"><x-admin.icon name="swords" class="h-4 w-4" /></span>
                <h2 class="ap-section-title">En juego</h2>
            </span>
            <span class="ap-badge ap-badge-neutral ap-num">{{ $inProgressMatches->count() }}</span>
        </div>
        @forelse($inProgressMatches as $match)
            <div class="ap-list-row flex-wrap" style="border-color: var(--ap-line)">
                <div class="ap-list-main">
                    <div class="ap-list-title">{{ $match->match_code }}</div>
                    <div class="ap-list-meta">{{ $match->zone_name }}</div>
                </div>
                @php
                    $isBotOnly = in_array((int) $match->id, $botOnlyMatchIds, true);
                    $hasReport = in_array((int) $match->id, $reportedMatchIds, true);
                @endphp
                <div class="flex flex-wrap gap-2">
                    @if($isBotOnly)
                        {{-- Solo bots: se puede cerrar de golpe, no hay puntos
                             de nadie de verdad en juego. --}}
                        @foreach(['team_a' => $match->team_a_realm, 'team_b' => $match->team_b_realm] as $side => $sideRealm)
                            <form method="POST" action="{{ route('admin.testing.resolve', $match) }}">
                                @csrf
                                <input type="hidden" name="winner_team" value="{{ $side }}">
                                <button type="submit" class="ap-btn ap-btn-sm">Gana {{ \App\Models\ArenaMatch::REALMS[$sideRealm] ?? ucfirst((string) $sideRealm) }}</button>
                            </form>
                        @endforeach
                    @elseif($hasReport)
                        <span class="ap-badge ap-badge-info">Reportado · te toca confirmar</span>
                    @else
                        {{-- Con una persona dentro no se cierra de golpe: el bot
                             sube su reporte y la persona confirma o rechaza,
                             que es el flujo que se quiere ensayar. --}}
                        <form method="POST" action="{{ route('admin.testing.bot-report', $match) }}">
                            @csrf
                            <button type="submit" class="ap-btn ap-btn-sm">
                                <x-admin.icon name="inbox" class="h-3.5 w-3.5" />
                                Que un bot reporte
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.matches.show', $match) }}" class="ap-btn ap-btn-sm ap-btn-quiet">Abrir</a>
                </div>
            </div>
        @empty
            <div class="ap-empty"><p class="m-0">Ninguna partida en juego.</p></div>
        @endforelse
    </section>

    {{-- Roster --}}
    <section class="ap-card ap-rise p-4 xl:col-span-2">
        <x-admin.section-head title="Bots del laboratorio" icon="bot"
                                note="Puedes encolar o sacar a cada uno por separado." />

        @forelse($playersByRealm as $realm => $realmPlayers)
            <details class="ap-details" open>
                <summary>
                    <x-admin.realm :realm="$realm" />
                    <span class="ap-section-note">{{ $realmPlayers->count() }} bots</span>
                </summary>
                <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3 pt-2">
                    @foreach($realmPlayers as $botPlayer)
                        @php($botQueue = $activeQueueByPlayer->get($botPlayer->id))
                        @php($isQueued = $botQueue && $botQueue->status === 'waiting' && !$botQueue->match_id)
                        <div class="ap-list-row flex-wrap" style="border-color: var(--ap-line); background: var(--ap-surface-raised)">
                            <div class="ap-list-main">
                                <div class="ap-list-title">{{ $botPlayer->character_name }}</div>
                                <div class="ap-list-meta">
                                    {{ \App\Models\Player::SUBCLASSES[$botPlayer->subclass] ?? ucfirst($botPlayer->subclass) }}
                                    · {{ number_format((float) $botPlayer->pl_points, 1) }} PL
                                    · {{ $botPlayer->mmr }} MMR
                                </div>
                            </div>
                            <form method="POST" action="{{ route('admin.testing.toggle-bot') }}" class="flex gap-1.5">
                                @csrf
                                <input type="hidden" name="player_id" value="{{ $botPlayer->id }}">
                                @if(!$isQueued)
                                    <select name="arena_mode" class="ap-select ap-select-sm" aria-label="Modalidad">
                                        @foreach($enabledModes as $sandboxMode)
                                            <option value="{{ $sandboxMode }}">{{ $sandboxMode }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button type="submit" class="ap-btn ap-btn-sm">{{ $isQueued ? 'Sacar' : 'Encolar' }}</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </details>
        @empty
            <div class="ap-empty">
                <x-admin.icon name="flask" class="h-6 w-6" />
                <p class="m-0">Todavia no hay bots. Generalos en el paso 1.</p>
            </div>
        @endforelse
    </section>

    {{-- Historial --}}
    <section class="ap-card ap-rise p-4 xl:col-span-2">
        <div class="ap-section-head">
            <span class="ap-section-lead">
                <span class="ap-section-mark"><x-admin.icon name="flask" class="h-4 w-4" /></span>
                <h2 class="ap-section-title">Ultimas partidas del laboratorio</h2>
            </span>
        </div>
        @forelse($recentMatches as $match)
            <a href="{{ route('admin.matches.show', $match) }}" class="ap-list-row">
                <div class="ap-list-main">
                    <div class="ap-list-title">{{ $match->match_code }}</div>
                    <div class="ap-list-meta">{{ $match->zone_name }} · <x-admin.ago :date="$match->created_at" /></div>
                </div>
                <x-admin.status :value="$match->status" />
            </a>
        @empty
            <div class="ap-empty"><p class="m-0">Todavia no se ha jugado nada con bots.</p></div>
        @endforelse
    </section>
</div>
@endsection
