{{--
    Avisar de que alguien se fue del combate.

    Se puede señalar a un rival o al propio compañero: quedarte solo en un 2v2
    es exactamente el caso que hay que poder denunciar, y hasta ahora no habia
    por donde.

    El aviso no castiga a nadie. Manda el enfrentamiento a moderacion y ahi se
    decide, asi que el texto lo dice claro: quien lo pulsa no esta sancionando,
    esta pidiendo que lo miren.
--}}
@php
    $yo = (int) ($viewerPlayer['player_id'] ?? 0);
    $senalables = $match->getAllPlayers()
        ->filter(fn ($p) => (int) $p['player_id'] !== $yo)
        ->values();

    $miEquipo = $match->getTeamSideForPlayer($yo);
    $avisosMios = $match->abandonmentReports
        ->where('reported_by_player_id', $yo)
        ->pluck('accused_player_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    $errorAbandono = $errors->has('error')
        || $errors->has('note')
        || $errors->has('accused_player_id')
        || $errors->has('files.*');
@endphp

@if($senalables->isNotEmpty())
    <section class="arena-panel mb-6 p-5 arena-animate-in arena-stagger-2" data-abandonment-panel>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">¿Alguien se fue?</p>
                <p class="mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Si un jugador abandonó el combate, avísalo. Lo revisa un administrador:
                    tu aviso no sanciona a nadie por sí solo.
                </p>
            </div>
            <button type="button" class="arena-btn-danger-ghost whitespace-nowrap" data-modal-open="modal-abandono">
                Reportar abandono
            </button>
        </div>

        <x-arena-modal id="modal-abandono" title="Reportar abandono" variant="danger">
            <p class="text-sm text-[color:var(--arena-muted)] arena-body-text">
                Esto <strong class="text-[color:var(--arena-text)]">no castiga a nadie todavía</strong>.
                El enfrentamiento pasa a revisión y un administrador decide. Si se confirma,
                solo se sanciona a quien abandonó.
            </p>

            @if($errorAbandono)
                <div class="mt-4 rounded-xl border border-rose-500/30 bg-rose-950/30 px-4 py-3 text-sm text-rose-200">
                    @foreach($errors->all() as $mensaje)
                        <p>{{ $mensaje }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('matches.abandonment.report') }}"
                  class="mt-4 space-y-4" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="match_id" value="{{ $match->id }}">
                <input type="hidden" name="player_id" value="{{ $yo }}">

                <fieldset>
                    <legend class="mb-2 block text-sm font-medium arena-body-text">¿Quién abandonó?</legend>
                    <div class="space-y-2">
                        @foreach($senalables as $candidato)
                            @php
                                $id = (int) $candidato['player_id'];
                                $esCompanero = $match->getTeamSideForPlayer($id) === $miEquipo;
                                $yaAvisado = in_array($id, $avisosMios, true);
                            @endphp
                            <label class="flex items-center gap-3 rounded-xl border border-[color:var(--arena-line)] px-4 py-3 {{ $yaAvisado ? 'opacity-50' : 'cursor-pointer hover:border-rose-500/40' }}">
                                <input type="radio" name="accused_player_id" value="{{ $id }}"
                                       {{ $yaAvisado ? 'disabled' : 'required' }}
                                       @checked((int) old('accused_player_id') === $id)>
                                <span class="flex-1">
                                    <span class="text-sm font-semibold text-[color:var(--arena-text)]">
                                        {{-- Al rival se le nombra por su subclase mientras siga siendo
                                             anonimo; al compañero por su nombre, que ya se conoce. --}}
                                        @if($esCompanero)
                                            {{ $candidato['character_name'] }}
                                        @else
                                            {{ \App\Models\Player::SUBCLASSES[$candidato['subclass']] ?? 'Guerrero' }} rival
                                        @endif
                                    </span>
                                    <span class="ml-2 text-xs text-[color:var(--arena-muted)]">
                                        {{ $esCompanero ? 'tu equipo' : 'equipo rival' }}
                                    </span>
                                    @if($yaAvisado)
                                        <span class="ml-2 text-xs text-amber-300">ya reportado</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">¿Qué pasó?</span>
                    <textarea name="note" rows="3" class="arena-textarea" required minlength="5" maxlength="500"
                              placeholder="Ej: se desconectó al minuto dos y no volvió">{{ old('note') }}</textarea>
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium arena-body-text">Capturas (opcional, hasta 3)</span>
                    <input type="file" name="files[]" accept="image/*" class="arena-field text-sm" multiple>
                </label>

                <div class="flex gap-3">
                    <button type="submit" class="arena-btn-danger">Enviar a revisión</button>
                    <button type="button" class="arena-btn-ghost" data-modal-close="modal-abandono">Cancelar</button>
                </div>
            </form>
        </x-arena-modal>
    </section>
@endif
