@extends('layouts.admin')

@section('title', 'Temporadas')
@section('page-title', 'Temporadas')
@section('page-subtitle', 'Fechas, cierre automatico y que pasa con el ranking cuando una temporada acaba')

@section('content')
@php
    $enZona = fn ($fecha) => $fecha?->copy()->setTimezone($zona)->format('Y-m-d\TH:i');
@endphp

@if(!$disponible)
    <div class="ap-flash ap-flash-danger" role="alert">
        <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
        <span>Las temporadas no estan disponibles: falta ejecutar las migraciones.</span>
    </div>
@else
<div class="grid gap-5 xl:grid-cols-[1fr_380px] items-start pb-16">
    <div class="flex flex-col gap-5 min-w-0">

        @if($actual)
            {{-- La temporada en marcha, con su barra. --}}
            <section class="ap-card ap-rise p-4">
                <x-admin.section-head title="{{ $actual->name }}" icon="calendar" tone="gold"
                                        note="La temporada abierta. Todo lo que se juega ahora cuenta para ella.">
                    <x-slot:aside>
                        @if($actual->vencida())
                            <span class="ap-badge ap-badge-warn"><span class="ap-badge-dot" aria-hidden="true"></span>Vencida: se cierra en el proximo minuto</span>
                        @elseif($actual->auto_close && $actual->ends_at)
                            <span class="ap-badge ap-badge-ok"><span class="ap-badge-dot" aria-hidden="true"></span>Cierre automatico</span>
                        @else
                            <span class="ap-badge ap-badge-neutral"><span class="ap-badge-dot" aria-hidden="true"></span>Cierre manual</span>
                        @endif
                    </x-slot:aside>
                </x-admin.section-head>

                @if($progreso)
                    <div class="ap-season" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                         aria-valuenow="{{ (int) round($progreso['porcentaje']) }}"
                         aria-label="Progreso de {{ $actual->name }}">
                        <div class="ap-season-track">
                            <span class="ap-season-fill" style="width: {{ $progreso['porcentaje'] }}%"></span>
                            @foreach($progreso['hitos'] as $hito)
                                <span class="ap-season-tick" style="left: {{ $hito['pos'] }}%" aria-hidden="true"></span>
                            @endforeach
                        </div>
                        <div class="ap-season-meta">
                            <span>{{ \App\Models\ArenaSeason::fechaCorta($progreso['inicio']) }}</span>
                            <b class="ap-num">
                                @if($progreso['estado'] === 'pendiente') Aun no ha empezado
                                @else Dia {{ $progreso['dia'] }} de {{ $progreso['dias'] }} · {{ (int) round($progreso['porcentaje']) }}%
                                @endif
                            </b>
                            <span>{{ \App\Models\ArenaSeason::fechaCorta($progreso['fin']) }}</span>
                        </div>
                        <p class="ap-hint">{{ $progreso['restante'] }}. Hora de {{ $zona }}.</p>
                    </div>
                @else
                    <p class="ap-maint-text">Sin fecha de fin: esta temporada sigue abierta hasta que la cierres a mano.</p>
                @endif
            </section>

            {{-- El calendario y lo que pasa al acabar. --}}
            <form method="POST" action="{{ route('admin.seasons.update', $actual) }}" class="ap-card ap-rise ap-delay-1 p-4">
                @csrf
                <x-admin.section-head title="Calendario y opciones" icon="sliders"
                                        note="Las fechas se escriben en hora de {{ $zona }}." />

                <div class="ap-field">
                    <label class="ap-label" for="t-name">Nombre</label>
                    <input type="text" id="t-name" name="name" maxlength="120" required value="{{ old('name', $actual->name) }}" class="ap-input">
                </div>

                <div class="grid gap-3 md:grid-cols-2 mt-3">
                    <div class="ap-field">
                        <label class="ap-label" for="t-start">Empieza</label>
                        <input type="datetime-local" id="t-start" name="starts_at" required
                               value="{{ old('starts_at', $enZona($actual->starts_at)) }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="t-days">Dura (días desde el inicio)</label>
                        <input type="number" id="t-days" name="duration_days" min="1" max="3650" placeholder="Opcional: calcula el fin" class="ap-input">
                        <span class="ap-hint">Alarga o acorta sin calcular fechas. Si rellenas esto, manda sobre "Termina".</span>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="t-end">Termina</label>
                        <input type="datetime-local" id="t-end" name="ends_at"
                               value="{{ old('ends_at', $enZona($actual->ends_at)) }}" class="ap-input">
                        <span class="ap-hint">Vacio = sin fecha: la barra no sale en la web.</span>
                    </div>
                </div>

                <label class="ap-switch-row mt-3">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Cerrarse sola al llegar a esa fecha</span>
                        <span class="ap-section-note">Congela el podio en el Salon de la Fama y abre la siguiente. Lo hace el mantenimiento, en el minuto siguiente.</span>
                    </span>
                    <input type="hidden" name="auto_close" value="0">
                    <input type="checkbox" class="ap-checkbox" name="auto_close" value="1" @checked(old('auto_close', $actual->auto_close))>
                </label>

                <p class="ap-label mt-4 mb-2">Al cerrarse...</p>

                <label class="ap-switch-row">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Abrir la siguiente temporada</span>
                        <span class="ap-section-note">Apagado, el ladder queda en pausa: ya no hay temporada en juego y solo se juegan amistosos (si estan encendidos) hasta que abras otra desde aqui.</span>
                    </span>
                    <input type="hidden" name="open_next" value="0">
                    <input type="checkbox" class="ap-checkbox" name="open_next" value="1" @checked(old('open_next', $actual->open_next))>
                </label>

                <p class="ap-label mt-4 mb-2">Si abre otra, esa...</p>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="ap-field">
                        <label class="ap-label" for="t-next">Se llama</label>
                        <input type="text" id="t-next" name="next_name" maxlength="120" placeholder="Si no, se deduce del nombre"
                               value="{{ old('next_name', $actual->next_name) }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="t-days">Dura (dias)</label>
                        <input type="number" id="t-days" name="next_duration_days" min="1" max="3650" placeholder="Sin fecha de fin"
                               value="{{ old('next_duration_days', $actual->next_duration_days) }}" class="ap-input">
                        <span class="ap-hint">Con dias, nace con su calendario y se cierra sola, y asi cada vez.</span>
                    </div>
                </div>

                <label class="ap-switch-row mt-3">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Reparte premios</span>
                        <span class="ap-section-note">Apagado, la portada vuelve a la marca y el podio de premios no sale. Los de esta temporada se guardan igualmente.</span>
                    </span>
                    <input type="hidden" name="next_prizes_enabled" value="0">
                    <input type="checkbox" class="ap-checkbox" name="next_prizes_enabled" value="1" @checked(old('next_prizes_enabled', $actual->next_prizes_enabled))>
                </label>

                <label class="ap-switch-row mt-2">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Poner el ranking a cero</span>
                        <span class="ap-section-note">Borra los enfrentamientos y deja a todos como recien creados, DESPUES de congelar el podio. Sin esto el ranking sigue como esta.</span>
                    </span>
                    <input type="hidden" name="reset_on_close" value="0">
                    <input type="checkbox" class="ap-checkbox" name="reset_on_close" value="1" @checked(old('reset_on_close', $actual->reset_on_close))>
                </label>

                <div class="mt-4 flex justify-end">
                    <button type="submit" class="ap-btn ap-btn-primary">
                        <x-admin.icon name="check" class="h-3.5 w-3.5" />
                        Guardar calendario
                    </button>
                </div>
            </form>
        @else
            {{-- Sin temporada abierta: la web no tiene calendario ni podio. --}}
            <form method="POST" action="{{ route('admin.seasons.open') }}" class="ap-card ap-rise p-4">
                @csrf
                <x-admin.section-head title="No hay ninguna temporada abierta" icon="calendar" tone="gold"
                                        note="El ladder esta en pausa: solo se juegan amistosos. Abre una temporada para reactivar el ranking competitivo." />
                <div class="ap-field">
                    <label class="ap-label" for="o-name">Nombre</label>
                    <input type="text" id="o-name" name="name" maxlength="120" required placeholder="Season 1" value="{{ old('name') }}" class="ap-input">
                </div>
                <div class="grid gap-3 md:grid-cols-2 mt-3">
                    <div class="ap-field">
                        <label class="ap-label" for="o-start">Empieza (vacio = ahora)</label>
                        <input type="datetime-local" id="o-start" name="starts_at" value="{{ old('starts_at') }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="o-end">Termina (opcional)</label>
                        <input type="datetime-local" id="o-end" name="ends_at" value="{{ old('ends_at') }}" class="ap-input">
                    </div>
                </div>
                <label class="ap-switch-row mt-3">
                    <span class="min-w-0"><span class="ap-switch-title">Cerrarse sola al llegar a esa fecha</span></span>
                    <input type="hidden" name="auto_close" value="0">
                    <input type="checkbox" class="ap-checkbox" name="auto_close" value="1" @checked(old('auto_close'))>
                </label>
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="ap-btn ap-btn-primary"><x-admin.icon name="plus" class="h-3.5 w-3.5" />Abrir temporada</button>
                </div>
            </form>
        @endif

        {{-- La temporada programada: se abre sola en su fecha, sea cuando sea. --}}
        @if(\App\Support\Esquema::columna('arena_seasons', 'prizes_on_open'))
            @if($programada)
                <section class="ap-card ap-rise p-4">
                    <x-admin.section-head title="Temporada programada" icon="calendar" tone="gold"
                                            note="Se abrira sola en su fecha. Mientras no abra, puedes cambiarla. Si el servidor estaba caido, abre al volver; si hay otra temporada abierta, espera a que se cierre." />
                    <form method="POST" action="{{ route('admin.seasons.schedule.update', $programada) }}">
                        @csrf @method('PUT')
                        <div class="ap-field">
                            <label class="ap-label" for="e-name">Nombre</label>
                            <input type="text" id="e-name" name="name" maxlength="120" required value="{{ old('name', $programada->name) }}" class="ap-input">
                        </div>
                        <div class="grid gap-3 md:grid-cols-2 mt-3">
                            <div class="ap-field">
                                <label class="ap-label" for="e-start">Empieza ({{ $zona }})</label>
                                <input type="datetime-local" id="e-start" name="starts_at" required value="{{ old('starts_at', $programada->starts_at->copy()->setTimezone($zona)->format('Y-m-d\TH:i')) }}" class="ap-input">
                            </div>
                            <div class="ap-field">
                                <label class="ap-label" for="e-days">Dura (días, opcional)</label>
                                <input type="number" id="e-days" name="duration_days" min="1" max="3650" placeholder="Sin fecha de fin" value="{{ old('duration_days', $programada->next_duration_days) }}" class="ap-input">
                            </div>
                        </div>
                        <label class="ap-switch-row mt-3">
                            <span class="min-w-0"><span class="ap-switch-title">Reparte premios</span></span>
                            <input type="hidden" name="prizes" value="0">
                            <input type="checkbox" class="ap-checkbox" name="prizes" value="1" @checked(old('prizes', $programada->prizes_on_open))>
                        </label>
                        <label class="ap-switch-row mt-3">
                            <span class="min-w-0"><span class="ap-switch-title">Poner el ranking a cero al cerrarse</span></span>
                            <input type="hidden" name="reset_on_close" value="0">
                            <input type="checkbox" class="ap-checkbox" name="reset_on_close" value="1" @checked(old('reset_on_close', $programada->reset_on_close))>
                        </label>
                        <label class="ap-switch-row mt-3">
                            <span class="min-w-0"><span class="ap-switch-title">Abrir la siguiente al cerrarse</span></span>
                            <input type="hidden" name="open_next" value="0">
                            <input type="checkbox" class="ap-checkbox" name="open_next" value="1" @checked(old('open_next', $programada->open_next))>
                        </label>
                        @if($actual && (!$actual->auto_close || !$actual->ends_at))
                            <p class="ap-hint mt-2" style="color: var(--ap-warn, #e0b34a)">Ojo: la temporada abierta no tiene cierre automático, así que esta no se abrirá hasta que la cierres a mano.</p>
                        @endif
                        <div class="mt-4 flex flex-wrap justify-end gap-2">
                            <button type="submit" class="ap-btn ap-btn-primary"><x-admin.icon name="check" class="h-3.5 w-3.5" />Guardar cambios</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('admin.seasons.schedule.cancel', $programada) }}" class="mt-2 flex justify-end" onsubmit="return confirm('¿Cancelar la temporada programada?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="ap-btn ap-btn-danger"><x-admin.icon name="trash" class="h-3.5 w-3.5" />Cancelar programación</button>
                    </form>
                </section>
            @else
                <form method="POST" action="{{ route('admin.seasons.schedule') }}" class="ap-card ap-rise p-4">
                    @csrf
                    <x-admin.section-head title="Programar una temporada" icon="calendar" tone="gold"
                                            note="Opcional. Se abrira sola en la fecha y hora que pongas (hora de {{ $zona }}). Sin programar nada, el ladder sigue como esta." />
                    <div class="ap-field">
                        <label class="ap-label" for="p-name">Nombre</label>
                        <input type="text" id="p-name" name="name" maxlength="120" required placeholder="Season 1" value="{{ old('name') }}" class="ap-input">
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 mt-3">
                        <div class="ap-field">
                            <label class="ap-label" for="p-start">Empieza</label>
                            <input type="datetime-local" id="p-start" name="starts_at" required value="{{ old('starts_at') }}" class="ap-input">
                        </div>
                        <div class="ap-field">
                            <label class="ap-label" for="p-days">Dura (días, opcional)</label>
                            <input type="number" id="p-days" name="duration_days" min="1" max="3650" placeholder="Sin fecha de fin" value="{{ old('duration_days') }}" class="ap-input">
                        </div>
                    </div>
                    <label class="ap-switch-row mt-3">
                        <span class="min-w-0"><span class="ap-switch-title">Reparte premios</span></span>
                        <input type="hidden" name="prizes" value="0">
                        <input type="checkbox" class="ap-checkbox" name="prizes" value="1" @checked(old('prizes', true))>
                    </label>
                    <label class="ap-switch-row mt-3">
                        <span class="min-w-0"><span class="ap-switch-title">Poner el ranking a cero al cerrarse</span></span>
                        <input type="hidden" name="reset_on_close" value="0">
                        <input type="checkbox" class="ap-checkbox" name="reset_on_close" value="1" @checked(old('reset_on_close'))>
                    </label>
                    <label class="ap-switch-row mt-3">
                        <span class="min-w-0"><span class="ap-switch-title">Abrir la siguiente al cerrarse</span></span>
                        <input type="hidden" name="open_next" value="0">
                        <input type="checkbox" class="ap-checkbox" name="open_next" value="1" @checked(old('open_next'))>
                    </label>
                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="ap-btn ap-btn-primary"><x-admin.icon name="calendar" class="h-3.5 w-3.5" />Programar apertura</button>
                    </div>
                </form>
            @endif
        @endif

        {{-- El historial. --}}
        <section class="ap-card ap-rise ap-delay-2 p-4">
            <x-admin.section-head title="Temporadas cerradas" icon="trophy"
                                    note="Cada una con su podio congelado en el Salon de la Fama." />
            @if($historial->isEmpty())
                <p class="ap-maint-text">Todavia no se ha cerrado ninguna.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ap-table">
                        <thead>
                            <tr><th>Temporada</th><th>Del</th><th>Al</th><th>Cierre</th><th class="text-right">En la vitrina</th></tr>
                        </thead>
                        <tbody>
                            @foreach($historial as $pasada)
                                <tr>
                                    <td><b>{{ $pasada->name }}</b></td>
                                    <td class="ap-num">{{ $pasada->starts_at ? \App\Models\ArenaSeason::fechaCorta($pasada->starts_at) : '—' }}</td>
                                    <td class="ap-num">{{ $pasada->ends_at ? \App\Models\ArenaSeason::fechaCorta($pasada->ends_at) : '—' }}</td>
                                    <td>
                                        <span class="ap-badge {{ $pasada->closed_reason === 'auto' ? 'ap-badge-ok' : 'ap-badge-neutral' }}">
                                            {{ $pasada->closed_reason === 'auto' ? 'Automatico' : 'Manual' }}
                                        </span>
                                    </td>
                                    <td class="ap-num text-right">{{ $congelados[$pasada->id] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <a href="{{ route('hall-of-fame') }}" target="_blank" rel="noopener" class="ap-btn ap-btn-sm ap-btn-quiet mt-3">
                    <x-admin.icon name="external" class="h-3.5 w-3.5" />
                    Ver el Salon de la Fama
                </a>
            @endif
        </section>
    </div>

    {{-- Cerrar a mano, a un lado: es la accion que no se deshace. --}}
    @if($actual)
        <aside class="flex flex-col gap-5">
            <form method="POST" action="{{ route('admin.season.close') }}" class="ap-card ap-rise ap-delay-1 p-4 ap-maint-block is-danger">
                @csrf
                <input type="hidden" name="esperada" value="{{ $actual->id }}">
                <x-admin.section-head title="Cerrar ahora" icon="trophy" tone="gold"
                                        note="Congela el podio en el Salon de la Fama con las cifras de hoy y abre la siguiente. No se deshace." />

                <div class="ap-field">
                    <label class="ap-label" for="c-next">Nombre de la siguiente</label>
                    <input type="text" id="c-next" name="siguiente" maxlength="120" class="ap-input"
                           placeholder="{{ $actual->next_name ?: 'Se deduce del nombre' }}" autocomplete="off">
                </div>
                <div class="ap-field">
                    <label class="ap-label" for="c-days">Dura (dias, opcional)</label>
                    <input type="number" id="c-days" name="duracion_dias" min="1" max="3650" class="ap-input"
                           value="{{ $actual->next_duration_days }}" placeholder="Sin fecha de fin">
                </div>

                <label class="ap-switch-row">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Abrir la siguiente temporada</span>
                        <span class="ap-section-note">Apagado: el ladder queda en pausa y solo hay amistosos.</span>
                    </span>
                    <input type="hidden" name="abrir_siguiente" value="0">
                    <input type="checkbox" class="ap-checkbox" name="abrir_siguiente" value="1" @checked($actual->open_next)>
                </label>
                <label class="ap-switch-row">
                    <span class="min-w-0"><span class="ap-switch-title">La siguiente reparte premios</span></span>
                    <input type="hidden" name="premios_siguiente" value="0">
                    <input type="checkbox" class="ap-checkbox" name="premios_siguiente" value="1" @checked($actual->next_prizes_enabled)>
                </label>
                <label class="ap-switch-row">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Poner el ranking a cero</span>
                        <span class="ap-section-note">Despues de congelar el podio.</span>
                    </span>
                    <input type="hidden" name="resetear" value="0">
                    <input type="checkbox" class="ap-checkbox" name="resetear" value="1" @checked($actual->reset_on_close)>
                </label>
                <label class="ap-switch-row">
                    <span class="min-w-0">
                        <span class="ap-switch-title">Cerrarla aunque este vacia</span>
                        <span class="ap-section-note">Solo hace falta si acaba de abrirse y no se ha jugado nada.</span>
                    </span>
                    <input type="hidden" name="forzar" value="0">
                    <input type="checkbox" class="ap-checkbox" name="forzar" value="1">
                </label>

                <div class="ap-field">
                    <label class="ap-label" for="c-confirm">Escribe CERRAR para confirmar</label>
                    <input type="text" id="c-confirm" name="confirmacion" class="ap-input" placeholder="CERRAR" autocomplete="off">
                </div>
                @error('confirmacion')
                    <p class="ap-error">{{ $message }}</p>
                @enderror

                <button class="ap-btn ap-btn-danger"
                        onclick="return confirm('La temporada actual pasa al Salon de la Fama y se abre una nueva. ¿Seguir?')">
                    <x-admin.icon name="trophy" class="h-3.5 w-3.5" />
                    Cerrar la temporada
                </button>
            </form>
        </aside>
    @endif
</div>
@endif
@endsection
