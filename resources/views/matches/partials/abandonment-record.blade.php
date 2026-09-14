{{--
    Lo que se avisó y lo que decidió moderación sobre un abandono.

    Lo ven las dos partes: quien avisó necesita saber en qué quedó, y el
    señalado necesita poder leer de qué se le acusa y con qué pruebas. Si solo
    lo viera uno, la queja del otro seguiría sin respuesta, que es el agujero
    que ya teníamos con los rechazos de reporte.
--}}
@php
    $avisos = $match->abandonmentReports ?? collect();
    $yo = (int) ($viewerPlayer['player_id'] ?? 0);
@endphp

@if($avisos->isNotEmpty())
    <section class="arena-panel mb-6 p-6 arena-animate-in" data-abandonment-record>
        <p class="arena-kicker">Abandono</p>
        <h2 class="mt-1 text-2xl font-semibold text-white">
            {{ $avisos->count() === 1 ? 'Aviso de abandono' : 'Avisos de abandono' }}
        </h2>

        <div class="mt-5 space-y-3">
            @foreach($avisos as $aviso)
                @php
                    $acusadoId = (int) $aviso->accused_player_id;
                    $avisadorId = (int) $aviso->reported_by_player_id;
                    $meSeñalan = $acusadoId === $yo;
                    $loAviseYo = $avisadorId === $yo;

                    $borde = match ($aviso->status) {
                        'confirmed' => 'border-l-rose-500/60',
                        'dismissed' => 'border-l-emerald-500/60',
                        default => 'border-l-amber-500/60',
                    };

                    // Mismo criterio que el resto de la pantalla: los nombres
                    // del rival solo se enseñan con el enfrentamiento cerrado.
                    $nombre = function (?\App\Models\Player $p, int $id) use ($match, $yo, $showRivalNames) {
                        if ($id === $yo) { return 'tú'; }
                        if (!$p) { return 'un jugador retirado'; }

                        $mismoEquipo = $match->getTeamSideForPlayer($id) === $match->getTeamSideForPlayer($yo);

                        return ($showRivalNames || $mismoEquipo)
                            ? $p->character_name
                            : (\App\Models\Player::SUBCLASSES[$p->subclass] ?? 'Guerrero') . ' rival';
                    };
                @endphp

                <article class="arena-card border-l-4 {{ $borde }} p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-semibold text-[color:var(--arena-text)]">
                            {{ ucfirst($nombre($aviso->reporter, $avisadorId)) }}
                            {{ $loAviseYo ? 'señalaste a' : 'señala a' }}
                            <span class="{{ $meSeñalan ? 'text-rose-300' : '' }}">{{ $nombre($aviso->accused, $acusadoId) }}</span>
                        </p>
                        <span class="text-[0.7rem] text-[color:var(--arena-muted)]">
                            {{ $aviso->created_at?->locale('es')->isoFormat('D MMM YYYY, HH:mm') }}
                        </span>
                    </div>

                    @if($aviso->note)
                        <p class="mt-2 whitespace-pre-line text-sm text-[color:var(--arena-text)] arena-body-text">{{ $aviso->note }}</p>
                    @endif

                    @if($aviso->evidenceItems() !== [])
                        <div class="mt-3 grid gap-2 sm:grid-cols-{{ count($aviso->evidenceItems()) > 1 ? '2' : '1' }}">
                            @foreach($aviso->evidenceItems() as $prueba)
                                <a href="{{ $prueba['url'] }}" target="_blank" class="arena-btn-ghost justify-center text-xs">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/></svg>
                                    {{ $prueba['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <p class="mt-3 text-xs {{ $aviso->status === 'pending' ? 'text-amber-300' : 'text-[color:var(--arena-muted)]' }} arena-body-text">
                        @switch($aviso->status)
                            @case('confirmed')
                                Moderación confirmó el abandono. Solo el jugador señalado fue sancionado.
                                @break
                            @case('dismissed')
                                Moderación descartó el aviso. Nadie fue sancionado.
                                @break
                            @default
                                Pendiente de revisión. Nadie ha sido sancionado todavía.
                        @endswitch
                        @if($aviso->admin_note)
                            <span class="mt-1 block text-[color:var(--arena-text)]">{{ $aviso->admin_note }}</span>
                        @endif
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
