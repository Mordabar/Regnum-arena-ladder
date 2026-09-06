@extends('layouts.admin')

@section('title', 'Enfrentamientos')
@section('page-title', 'Enfrentamientos')
@section('page-subtitle', 'Historial completo de partidas, con o sin reporte')

@section('page-actions')
    <a href="{{ route('admin.inbox') }}" class="ap-btn ap-btn-sm">
        <x-admin.icon name="inbox" class="h-3.5 w-3.5" />
        Ir a moderacion
    </a>
@endsection

@section('content')
@php
    $activeFilters = collect([
        $status ? \App\Models\ArenaMatch::STATUSES[$status] ?? $status : null,
        $mode,
        $search !== '' ? '"' . $search . '"' : null,
    ])->filter();
@endphp

<form method="GET" class="ap-filters ap-rise mb-4">
    <div class="ap-field flex-1" style="min-width: 220px">
        <label class="ap-label" for="f-q">Buscar</label>
        <input type="search" id="f-q" name="q" value="{{ $search }}" class="ap-input"
               placeholder="Codigo ARENA-1234, token o nombre de personaje">
    </div>

    <div class="ap-field">
        <label class="ap-label" for="f-status">Estado</label>
        <select name="status" id="f-status" class="ap-select">
            <option value="">Cualquiera</option>
            @foreach(\App\Models\ArenaMatch::STATUSES as $key => $label)
                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="ap-field">
        <label class="ap-label" for="f-mode">Modalidad</label>
        <select name="mode" id="f-mode" class="ap-select">
            <option value="">Las dos</option>
            @foreach(array_keys(\App\Support\ArenaMode::MODES) as $key)
                <option value="{{ $key }}" @selected($mode === $key)>{{ $key }}</option>
            @endforeach
        </select>
    </div>

    <button type="submit" class="ap-btn ap-btn-primary">
        <x-admin.icon name="search" class="h-3.5 w-3.5" />
        Filtrar
    </button>

    @if($activeFilters->isNotEmpty())
        <a href="{{ route('admin.matches.index') }}" class="ap-btn ap-btn-quiet">Limpiar</a>
    @endif
</form>

{{-- Borrar enfrentamientos.

     El laboratorio solo sabe quitar los suyos. Cuando las partidas que sobran
     son entre personajes de verdad -unas cuantas jugadas a mano para probar-
     no habia forma de quitarlas sin dejar el ranking descuadrado, y hacia
     falta entrar por consola. --}}
<form method="POST" action="{{ route('admin.matches.destroy') }}" data-matches-form>
    @csrf
    @method('DELETE')

    <div class="ap-card ap-rise ap-delay-1" style="overflow-x: auto">
        <div class="ap-bulkbar" data-bulk-bar hidden>
            <span><b data-bulk-count>0</b> seleccionados</span>
            <button type="submit" class="ap-btn ap-btn-sm ap-btn-danger"
                    onclick="return confirm('Se borran los enfrentamientos elegidos y se devuelve a cada jugador lo que le repartieron. ¿Seguir?')">
                <x-admin.icon name="trash" class="h-3.5 w-3.5" />
                Borrar y recalcular
            </button>
        </div>

    <table class="ap-table">
        <caption class="ap-sr-only">Enfrentamientos {{ $activeFilters->isNotEmpty() ? 'filtrados por ' . $activeFilters->implode(', ') : 'mas recientes primero' }}</caption>
        <thead>
            <tr>
                <th scope="col" style="width: 34px">
                    <input type="checkbox" data-bulk-all aria-label="Elegir todos los de esta pagina">
                </th>
                <th scope="col">Codigo</th>
                <th scope="col">Enfrentamiento</th>
                <th scope="col">Zona</th>
                <th scope="col">Estado</th>
                <th scope="col">Reporte</th>
                <th scope="col">Creado</th>
                <th scope="col"><span class="ap-sr-only">Acciones</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($matches as $match)
                <tr>
                    <td>
                        <input type="checkbox" name="match_ids[]" value="{{ $match->id }}"
                               data-bulk-item aria-label="Elegir {{ $match->match_code }}">
                    </td>
                    <th scope="row" style="font-weight: 500">
                        <a href="{{ route('admin.matches.show', $match) }}" class="ap-link">{{ $match->match_code }}</a>
                        <div class="ap-list-meta">{{ $match->queue_mode === 'premade' ? 'Premade' : 'Aleatoria' }}</div>
                    </th>
                    <td>
                        <span class="inline-flex items-center gap-1.5">
                            <x-admin.realm :realm="$match->team_a_realm" />
                            <span style="color: var(--ap-text-subtle)">vs</span>
                            <x-admin.realm :realm="$match->team_b_realm" />
                        </span>
                        <div class="ap-list-meta">{{ $match->arena_mode ?: '2v2' }}</div>
                    </td>
                    <td>{{ $match->zone_name }}</td>
                    <td><x-admin.status :value="$match->status" /></td>
                    <td>
                        @if($match->report)
                            <x-admin.status kind="report" :value="$match->report->status" />
                        @else
                            <span style="color: var(--ap-text-subtle)">Sin reportar</span>
                        @endif
                    </td>
                    <td><x-admin.ago :date="$match->created_at" /></td>
                    <td style="text-align: right">
                        <a href="{{ route('admin.matches.show', $match) }}" class="ap-btn ap-btn-sm ap-btn-quiet">
                            Abrir <x-admin.icon name="arrow-right" class="h-3 w-3" />
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="ap-empty">
                            <x-admin.icon name="search" class="h-6 w-6" />
                            <p class="m-0">
                                @if($activeFilters->isNotEmpty())
                                    Ningun enfrentamiento coincide con {{ $activeFilters->implode(' · ') }}.
                                @else
                                    Todavia no se ha jugado ningun enfrentamiento.
                                @endif
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</form>

{{ $matches->links('vendor.pagination.admin') }}

{{-- Mantenimiento del ranking.

     Cuando las cifras de un personaje no cuadran con sus partidas -paso al
     purgar el laboratorio, que devolvia puntos que nadie llego a perder- esto
     las rehace desde el historial que queda. --}}
<div class="ap-card ap-rise ap-delay-2 mt-5">
    <x-admin.section-head icon="refresh" title="Mantenimiento del ranking"
        note="Rehacer las puntuaciones desde los enfrentamientos, o empezar de cero" />

    @if(session('ladder_preview'))
        <div class="ap-preview">
            <p class="ap-preview-head">Esto es lo que cambiaria:</p>
            <ul class="ap-preview-list">
                @foreach(session('ladder_preview') as $fila)
                    <li>
                        <b>{{ $fila['character_name'] }}</b>
                        PL {{ $fila['antes']['pl_points'] }} → {{ $fila['despues']['pl_points'] }} ·
                        MMR {{ $fila['antes']['mmr'] }} → {{ $fila['despues']['mmr'] }} ·
                        {{ $fila['antes']['wins'] }}/{{ $fila['antes']['losses'] }} → {{ $fila['despues']['wins'] }}/{{ $fila['despues']['losses'] }} ·
                        {{ $fila['antes']['matches_played'] }} → {{ $fila['despues']['matches_played'] }} partidas
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="ap-maint">
        <form method="POST" action="{{ route('admin.ladder.recalculate') }}" class="ap-maint-block">
            @csrf
            <input type="hidden" name="dry_run" value="1">
            <p class="ap-maint-text">
                Compara cada personaje con sus enfrentamientos y enseña lo que no cuadra,
                sin tocar nada.
            </p>
            <button class="ap-btn ap-btn-sm">
                <x-admin.icon name="search" class="h-3.5 w-3.5" />
                Revisar sin tocar
            </button>
        </form>

        <form method="POST" action="{{ route('admin.ladder.recalculate') }}" class="ap-maint-block">
            @csrf
            <p class="ap-maint-text">
                Rehace PL, MMR y el historial de todos desde los enfrentamientos que
                quedan. No inventa nada: cada partida ya guarda como quedo el jugador.
            </p>
            <button class="ap-btn ap-btn-sm ap-btn-primary"
                    onclick="return confirm('Se rehacen las puntuaciones de todos los personajes desde su historial. ¿Seguir?')">
                <x-admin.icon name="refresh" class="h-3.5 w-3.5" />
                Recalcular el ranking
            </button>
        </form>

        <form method="POST" action="{{ route('admin.ladder.reset') }}" class="ap-maint-block is-danger">
            @csrf
            <p class="ap-maint-text">
                Borra <b>todos</b> los enfrentamientos y deja a cada personaje como
                recien creado. Los personajes no se borran.
            </p>
            <label class="ap-field">
                <span class="ap-label" for="reset-confirm">Escribe REINICIAR para confirmar</span>
                <input type="text" id="reset-confirm" name="confirmacion" class="ap-input" placeholder="REINICIAR" autocomplete="off">
            </label>
            @error('confirmacion')
                <p class="ap-error">{{ $message }}</p>
            @enderror
            <button class="ap-btn ap-btn-sm ap-btn-danger"
                    onclick="return confirm('Se borra TODO el historial de enfrentamientos y el ranking queda a cero. ¿Seguir?')">
                <x-admin.icon name="trash" class="h-3.5 w-3.5" />
                Reiniciar el ranking
            </button>
        </form>
    </div>
</div>

@push('scripts')
<script>
    /* La barra de borrado solo aparece cuando hay algo elegido: un boton de
       borrar siempre visible sobre una tabla invita al accidente. */
    (function () {
        var form = document.querySelector('[data-matches-form]');
        if (!form) { return; }

        var barra = form.querySelector('[data-bulk-bar]');
        var cuenta = form.querySelector('[data-bulk-count]');
        var todos = form.querySelector('[data-bulk-all]');

        function repintar() {
            var elegidos = form.querySelectorAll('[data-bulk-item]:checked').length;
            cuenta.textContent = elegidos;
            barra.hidden = elegidos === 0;

            if (todos) {
                var total = form.querySelectorAll('[data-bulk-item]').length;
                todos.checked = elegidos > 0 && elegidos === total;
                todos.indeterminate = elegidos > 0 && elegidos < total;
            }
        }

        form.addEventListener('change', function (event) {
            if (event.target === todos) {
                form.querySelectorAll('[data-bulk-item]').forEach(function (casilla) {
                    casilla.checked = todos.checked;
                });
            }

            repintar();
        });

        repintar();
    })();
</script>
@endpush
@endsection
