@props(['season' => null])
@php
    // La barra del calendario de la temporada: cuanto lleva y cuanto queda.
    // Sin fecha de fin no hay nada que medir, asi que no se pinta: una barra
    // sin final seria una mentira bonita.
    $p = $season?->progreso();
    $pct = $p ? $p['porcentaje'] : 0;
    $entero = (int) round($pct);
    // "8 abr 2026"; el orden y los meses los pone el idioma.
    $fmt = fn ($f) => __(':d :m :y', ['d' => $f->day, 'm' => __(['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][$f->month - 1]), 'y' => $f->year]);
@endphp

@if($p)
    <div class="arena-season is-{{ str_replace('_', '-', $p['estado']) }}" role="group" aria-label="{{ __('Calendario de :name', ['name' => $season->name]) }}"
         style="--p: {{ $pct }}%">
        <div class="arena-season-top">
            <span class="arena-season-name">
                <span class="arena-season-live" aria-hidden="true"></span>
                {{ $season->name }}
            </span>
            <span class="arena-season-left">{{ $p['restante'] }}</span>
        </div>

        {{-- El riel. El relleno crece desde la izquierda al cargar y la cabeza
             marca "hoy"; las muescas son el primer dia de cada mes. --}}
        <div class="arena-season-track" role="progressbar" aria-valuemin="0" aria-valuemax="100"
             aria-valuenow="{{ $entero }}"
             aria-valuetext="{{ $p['estado'] === 'pendiente' ? __('Aún no ha empezado') : __('Día :dia de :dias, :pct por ciento', ['dia' => $p['dia'], 'dias' => $p['dias'], 'pct' => $entero]) }}">
            <span class="arena-season-fill"></span>
            @foreach($p['hitos'] as $hito)
                <span class="arena-season-tick @if($hito['pos'] <= $pct) is-passed @endif" style="left: {{ $hito['pos'] }}%" aria-hidden="true"></span>
            @endforeach
            <span class="arena-season-head" aria-hidden="true"></span>
        </div>

        <div class="arena-season-scale" aria-hidden="true">
            @foreach($p['hitos'] as $hito)
                <span style="left: {{ $hito['pos'] }}%">{{ $hito['mes'] }}</span>
            @endforeach
        </div>

        <div class="arena-season-foot">
            <span class="arena-season-date">{{ $fmt($p['inicio']) }}</span>
            <b class="arena-season-day">
                @if($p['estado'] === 'pendiente')
                    Próximamente
                @else
                    Día {{ $p['dia'] }} <i>de {{ $p['dias'] }}</i> · {{ $entero }}%
                @endif
            </b>
            <span class="arena-season-date">{{ $fmt($p['fin']) }}</span>
        </div>
    </div>
@endif
