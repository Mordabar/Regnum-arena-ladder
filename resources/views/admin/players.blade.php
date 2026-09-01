@extends('layouts.admin')

@section('title', 'Jugadores')
@section('page-title', 'Jugadores')
@section('page-subtitle', 'Personajes registrados, su estado y las acciones de moderacion')

@section('page-actions')
    <button type="button" class="ap-btn ap-btn-sm" data-ap-toggle="ap-create-player" aria-expanded="false" aria-controls="ap-create-player">
        <x-admin.icon name="users" class="h-3.5 w-3.5" />
        Crear jugador
    </button>
@endsection

@section('content')

{{-- Alta manual: plegada por defecto. Es una operacion rara y no deberia
     competir por atencion con la lista, que es lo que se usa a diario. --}}
<section class="ap-card ap-rise mb-4 p-4" id="ap-create-player" hidden>
    <div class="ap-section-head">
        <div>
            <h2 class="ap-section-title">Crear jugador desde el panel</h2>
            <p class="ap-section-note">Genera un usuario administrado y su personaje. Util para pruebas y para reponer una cuenta perdida.</p>
        </div>
    </div>
    <form method="POST" action="{{ route('admin.players.store') }}" class="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
        @csrf
        <div class="ap-field">
            <label class="ap-label" for="p-owner">Propietario</label>
            <input type="text" id="p-owner" name="owner_label" class="ap-input" value="{{ old('owner_label') }}" placeholder="Gestionado por admin" required>
        </div>
        <div class="ap-field">
            <label class="ap-label" for="p-email">Email (opcional)</label>
            <input type="email" id="p-email" name="owner_email" class="ap-input" value="{{ old('owner_email') }}" placeholder="owner@example.com">
        </div>
        <div class="ap-field">
            <label class="ap-label" for="p-name">Nombre del personaje</label>
            <input type="text" id="p-name" name="character_name" class="ap-input" value="{{ old('character_name') }}" required>
        </div>
        <div class="ap-field">
            <label class="ap-label" for="p-realm">Reino</label>
            <select name="realm" id="p-realm" class="ap-select" required>
                @foreach(\App\Models\Player::REALMS as $key => $label)
                    <option value="{{ $key }}" @selected(old('realm') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="ap-field">
            <label class="ap-label" for="p-subclass">Subclase</label>
            <select name="subclass" id="p-subclass" class="ap-select" required>
                @foreach(\App\Models\Player::SUBCLASSES as $key => $label)
                    <option value="{{ $key }}" @selected(old('subclass') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="ap-field">
            <label class="ap-label" for="p-pl">PL inicial</label>
            <input type="number" step="0.1" min="0" max="500" id="p-pl" name="pl_points" class="ap-input" value="{{ old('pl_points', 0) }}">
        </div>
        <div class="ap-field">
            <label class="ap-label" for="p-mmr">MMR inicial</label>
            <input type="number" min="100" max="5000" id="p-mmr" name="mmr" class="ap-input" value="{{ old('mmr', 800) }}">
            <span class="ap-hint">800 es el valor de una cuenta nueva.</span>
        </div>
        <div class="ap-field justify-end">
            <button type="submit" class="ap-btn ap-btn-primary ap-btn-block">Crear jugador</button>
        </div>
    </form>
</section>

<form method="GET" class="ap-filters ap-rise mb-4">
    <div class="ap-field flex-1" style="min-width: 200px">
        <label class="ap-label" for="f-q">Buscar</label>
        <input type="search" id="f-q" name="q" value="{{ $search }}" class="ap-input" placeholder="Nombre del personaje o de Discord">
    </div>
    <div class="ap-field">
        <label class="ap-label" for="f-realm">Reino</label>
        <select name="realm" id="f-realm" class="ap-select">
            <option value="">Los tres</option>
            @foreach(\App\Models\Player::REALMS as $key => $label)
                <option value="{{ $key }}" @selected($realm === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="ap-field">
        <label class="ap-label" for="f-status">Estado</label>
        <select name="status" id="f-status" class="ap-select">
            <option value="">Cualquiera</option>
            <option value="active" @selected($status === 'active')>Habilitados</option>
            <option value="inactive" @selected($status === 'inactive')>Fuera de juego (todos)</option>
            <option value="disabled" @selected($status === 'disabled')>Deshabilitados</option>
            <option value="deleted" @selected($status === 'deleted')>Eliminados por su dueño</option>
            <option value="locked" @selected($status === 'locked')>Sancionados</option>
            <option value="dormant" @selected($status === 'dormant')>Sin actividad ({{ $dormancyDays }}+ dias)</option>
        </select>
    </div>
    <button type="submit" class="ap-btn ap-btn-primary">
        <x-admin.icon name="search" class="h-3.5 w-3.5" />
        Filtrar
    </button>
    @if($search !== '' || $realm || $status)
        <a href="{{ route('admin.players.index') }}" class="ap-btn ap-btn-quiet">Limpiar</a>
    @endif
</form>

<div class="ap-card ap-rise ap-delay-1" style="overflow-x: auto">
    <table class="ap-table">
        <thead>
            <tr>
                <th scope="col">Personaje</th>
                <th scope="col">Estado</th>
                <th scope="col" style="text-align: right">PL</th>
                <th scope="col" style="text-align: right">MMR</th>
                <th scope="col" style="text-align: right">V/D</th>
                <th scope="col" style="text-align: right">Confianza</th>
                <th scope="col"><span class="ap-sr-only">Acciones</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($players as $player)
                @php
                    $activeQueue = $player->queues->first();
                    $isLocked = $player->queue_locked_until && $player->queue_locked_until->isFuture();
                    $rowId = 'ap-player-' . $player->id;
                    // Sin actividad = su dueno no pasa por el sitio desde hace
                    // $dormancyDays dias. Nada que ver con is_active, que es el
                    // interruptor manual del personaje.
                    $isDormant = $player->isDormant($dormancyDays);
                @endphp
                <tr>
                    <th scope="row" style="font-weight: 500">
                        <div class="flex items-center gap-2">
                            <x-admin.realm :realm="$player->realm" />
                            <span>{{ $player->character_name }}</span>
                        </div>
                        <div class="ap-list-meta">
                            {{ \App\Models\Player::SUBCLASSES[$player->subclass] ?? ucfirst($player->subclass) }}
                            @if($player->user?->discord_username) · {{ $player->user->discord_username }} @endif
                        </div>
                    </th>
                    <td>
                        <div class="flex flex-wrap gap-1.5">
                            @if($isLocked)
                                <span class="ap-badge ap-badge-danger"><span class="ap-badge-dot"></span>Sancionado</span>
                            @elseif(!$player->is_active)
                                <span class="ap-badge ap-badge-neutral"><span class="ap-badge-dot"></span>{{ $player->statusLabel() }}</span>
                            @else
                                <span class="ap-badge ap-badge-ok"><span class="ap-badge-dot"></span>Activo</span>
                            @endif
                            @if($activeQueue)
                                <span class="ap-badge ap-badge-info"><span class="ap-badge-dot"></span>En cola {{ $activeQueue->arena_mode ?: '2v2' }}</span>
                            @endif
                            @if($isDormant)
                                <span class="ap-badge ap-badge-neutral" title="Sin entrar al sitio desde hace {{ $dormancyDays }} dias o mas"><span class="ap-badge-dot"></span>Sin actividad</span>
                            @endif
                        </div>
                        @if($isDormant)
                            <div class="ap-list-meta">
                                @if($player->user?->last_seen_at)
                                    Ultima visita <x-admin.ago :date="$player->user->last_seen_at" />
                                @else
                                    Sin visitas registradas
                                @endif
                            </div>
                        @endif
                        @if($isLocked)
                            <div class="ap-list-meta">
                                Hasta {{ $player->queue_locked_until->format('d/m H:i') }}
                                @if($player->queue_lock_reason_name) · {{ $player->queue_lock_reason_name }} @endif
                            </div>
                        @endif
                    </td>
                    <td class="ap-num" style="text-align: right; color: var(--ap-accent)">{{ number_format((float) $player->pl_points, 1) }}</td>
                    <td class="ap-num" style="text-align: right">{{ $player->mmr }}</td>
                    <td class="ap-num" style="text-align: right">{{ $player->wins }}/{{ $player->losses }}</td>
                    <td class="ap-num" style="text-align: right; {{ $player->trust_score < 70 ? 'color: var(--ap-warn)' : '' }}">
                        {{ $player->trust_score }}
                        @if($player->penalty_strikes > 0)
                            <div class="ap-list-meta">{{ $player->penalty_strikes }} {{ $player->penalty_strikes === 1 ? 'sancion' : 'sanciones' }}</div>
                        @endif
                    </td>
                    <td style="text-align: right">
                        <button type="button" class="ap-btn ap-btn-sm ap-btn-quiet"
                                data-ap-toggle="{{ $rowId }}" aria-expanded="false" aria-controls="{{ $rowId }}">
                            Acciones
                        </button>
                    </td>
                </tr>

                {{-- Las acciones viven plegadas: la lista se lee de un vistazo y
                     ningun boton destructivo queda a un clic de distancia por
                     accidente. --}}
                <tr id="{{ $rowId }}" hidden>
                    <td colspan="7" style="background: var(--ap-surface-raised)">
                        <div class="grid gap-3 lg:grid-cols-3">
                            <div class="ap-actions-group">
                                <p class="ap-actions-title">Rutina</p>
                                <div class="flex flex-wrap gap-2">
                                    @if($player->isDeletedByOwner())
                                        {{-- Lo borro su dueno: desde el lobby ya no puede volver, asi que
                                             la recuperacion pasa por aqui a proposito. --}}
                                        <form method="POST" action="{{ route('admin.players.update', $player) }}"
                                              data-ap-confirm="Vas a devolver '{{ $player->cleanName() }}' a su dueno y al ranking. ¿Seguro?">
                                            @csrf
                                            <input type="hidden" name="action" value="restore_deleted">
                                            <button type="submit" class="ap-btn ap-btn-sm">Recuperar personaje</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="toggle_active">
                                            <button type="submit" class="ap-btn ap-btn-sm">{{ $player->is_active ? 'Deshabilitar' : 'Habilitar' }}</button>
                                        </form>
                                    @endif
                                    @if(!$activeQueue)
                                        @php($adminEnabledModes = \App\Support\ArenaMode::enabled())
                                        <form method="POST" action="{{ route('admin.players.update', $player) }}" class="flex gap-2">
                                            @csrf
                                            <input type="hidden" name="action" value="enqueue_random">
                                            @if($player->subclass === 'conjurer')
                                                <select name="conjurer_role" class="ap-select ap-select-sm" aria-label="Rol del conjurador">
                                                    <option value="offensive">Ofensivo</option>
                                                    <option value="support">Soporte</option>
                                                </select>
                                            @endif
                                            {{-- Solo se ofrecen las modalidades encendidas: encolar en una apagada
                                                 dejaria al jugador esperando un match que nunca llega. --}}
                                            @if(count($adminEnabledModes) > 1)
                                                <select name="arena_mode" class="ap-select ap-select-sm" aria-label="Modalidad">
                                                    @foreach($adminEnabledModes as $adminMode)
                                                        <option value="{{ $adminMode }}">{{ $adminMode }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif(count($adminEnabledModes) === 1)
                                                <input type="hidden" name="arena_mode" value="{{ $adminEnabledModes[0] }}">
                                            @endif
                                            <button type="submit" class="ap-btn ap-btn-sm" @disabled(empty($adminEnabledModes))>Meter en cola</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="remove_from_queue">
                                            <button type="submit" class="ap-btn ap-btn-sm">Sacar de la cola</button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <div class="ap-actions-group">
                                <p class="ap-actions-title">Sanciones</p>
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('admin.players.update', $player) }}"
                                          data-ap-confirm="Bloquear a {{ $player->character_name }} durante 12 horas. No podra entrar en cola en ese tiempo.">
                                        @csrf
                                        <input type="hidden" name="action" value="lock_12h">
                                        <button type="submit" class="ap-btn ap-btn-sm">Bloquear 12 h</button>
                                    </form>
                                    @if($isLocked)
                                        <form method="POST" action="{{ route('admin.players.update', $player) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="unlock_queue">
                                            <button type="submit" class="ap-btn ap-btn-sm">Levantar sancion</button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <div class="ap-actions-group ap-actions-danger">
                                <p class="ap-actions-title" style="color: var(--ap-danger)">Irreversible</p>
                                <p class="ap-section-note mb-2">
                                    @if($player->matches_played > 0)
                                        Borra el personaje y purga sus {{ $player->matches_played }} partidas, resultados y reportes. El ladder se recalcula sin el.
                                    @else
                                        Borra el personaje. No ha jugado ninguna partida, asi que no afecta al ladder.
                                    @endif
                                </p>
                                <form method="POST" action="{{ route('admin.players.destroy', $player) }}"
                                      data-ap-confirm="Vas a borrar a {{ $player->character_name }} y todo su historial. Esto no se puede deshacer.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ap-btn ap-btn-sm ap-btn-danger">Eliminar personaje</button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="ap-empty">
                            <x-admin.icon name="users" class="h-6 w-6" />
                            <p class="m-0">Ningun jugador coincide con los filtros.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{ $players->links('vendor.pagination.admin') }}

@push('scripts')
<script>
    // Un unico patron de plegado para todo el panel: el boton dice a que
    // elemento apunta y el estado vive en aria-expanded.
    document.querySelectorAll('[data-ap-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.apToggle);
            if (!target) return;
            const open = target.hidden;
            target.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
</script>
@endpush
@endsection
