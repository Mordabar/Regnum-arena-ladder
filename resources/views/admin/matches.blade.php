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

<div class="ap-card ap-rise ap-delay-1" style="overflow-x: auto">
    <table class="ap-table">
        <caption class="ap-sr-only">Enfrentamientos {{ $activeFilters->isNotEmpty() ? 'filtrados por ' . $activeFilters->implode(', ') : 'mas recientes primero' }}</caption>
        <thead>
            <tr>
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
                    <td colspan="7">
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

{{ $matches->links('vendor.pagination.admin') }}
@endsection
