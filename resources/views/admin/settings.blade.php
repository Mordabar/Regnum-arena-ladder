@extends('layouts.admin')

@section('title', 'Reglas del ladder')
@section('page-title', 'Reglas del ladder')
@section('page-subtitle', 'Que modalidades estan abiertas, cuanto duran las ventanas y como se sanciona')

@section('content')
<form method="POST" action="{{ route('admin.settings.update') }}" class="pb-16">
    @csrf

    <div class="grid gap-5 xl:grid-cols-[1fr_330px] items-start">
        <div class="flex flex-col gap-5">

            {{-- Modalidades: lo primero, porque es el interruptor que decide si
                 hay juego o no. --}}
            <section class="ap-card ap-rise p-4">
                <x-admin.section-head title="Modalidades abiertas" icon="swords"
                                        note="Se encienden por separado y pueden convivir. El ranking es uno solo: una partida de 2v2 y una de 3v3 suman los mismos puntos a la misma tabla." />

                <div class="flex flex-col gap-2">
                    @foreach(['2v2' => ['Arena 2v2', 'Equipos de dos jugadores'], '3v3' => ['Arena 3v3', 'Equipos de tres jugadores']] as $modeKey => $modeInfo)
                        <label class="ap-switch-row">
                            <span class="min-w-0">
                                <span class="ap-switch-title">{{ $modeInfo[0] }}</span>
                                <span class="ap-section-note">{{ $modeInfo[1] }}</span>
                            </span>
                            {{-- El hidden garantiza que un checkbox destildado llegue como 0 --}}
                            <input type="hidden" name="mode_{{ $modeKey }}_enabled" value="0">
                            <input type="checkbox" class="ap-checkbox"
                                   name="mode_{{ $modeKey }}_enabled" value="1"
                                   @checked($settings['mode_' . $modeKey . '_enabled'])>
                        </label>
                    @endforeach
                </div>

                @if(!$settings['mode_2v2_enabled'] && !$settings['mode_3v3_enabled'])
                    <div class="ap-flash ap-flash-danger mt-3 mb-0">
                        <x-admin.icon name="alert" class="h-4 w-4 shrink-0" />
                        <span>
                            Con las dos apagadas nadie puede entrar en cola. Las partidas ya empezadas
                            siguen su curso normal hasta que se reporten.
                        </span>
                    </div>
                @endif
            </section>

            {{-- Ventanas de tiempo --}}
            <section class="ap-card ap-rise ap-delay-1 p-4">
                <x-admin.section-head title="Ventanas de tiempo" icon="clock" tone="info"
                                        note="Cuanto espera el sistema antes de resolver algo por su cuenta." />
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="ap-field">
                        <label class="ap-label" for="s-accept">Aceptar la partida</label>
                        <input type="number" min="1" max="30" id="s-accept" name="accept_window_minutes" value="{{ $settings['accept_window_minutes'] }}" class="ap-input">
                        <span class="ap-hint">Minutos para aceptar. Si nadie lo hace, la partida se cancela.</span>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-hunt">Duracion de la caceria</label>
                        <input type="number" min="5" max="120" id="s-hunt" name="hunt_window_minutes" value="{{ $settings['hunt_window_minutes'] }}" class="ap-input">
                        <span class="ap-hint">Minutos para encontrarse y pelear en la zona asignada.</span>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-report">Confirmar el resultado</label>
                        <input type="number" min="1" max="60" id="s-report" name="report_confirmation_window_minutes" value="{{ $settings['report_confirmation_window_minutes'] }}" class="ap-input">
                        <span class="ap-hint">Minutos que tiene el rival para confirmar o rechazar el reporte.</span>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-dormant">Marcar sin actividad (dias)</label>
                        <input type="number" min="1" max="365" id="s-dormant" name="inactive_after_days" value="{{ $settings['inactive_after_days'] }}" class="ap-input">
                        <span class="ap-hint">
                            Dias sin entrar al sitio tras los que un jugador aparece como "Sin actividad".
                            Es solo una etiqueta para el panel: no le impide encolar ni le quita puntos.
                        </span>
                    </div>
                </div>
            </section>

            {{-- Balance --}}
            <section class="ap-card ap-rise ap-delay-2 p-4">
                <x-admin.section-head title="Equilibrio entre aleatorias y premades" icon="scale"
                                        note="Un equipo premade juega coordinado; estos porcentajes compensan a quien entra en cola solo." />
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="ap-field md:col-span-2">
                        <label class="ap-label" for="s-premade-limit">Partidas premade por equipo y dia</label>
                        <input type="number" min="1" max="10" id="s-premade-limit" name="premade_daily_limit" value="{{ $settings['premade_daily_limit'] }}" class="ap-input" style="max-width: 140px">
                        <span class="ap-hint">Un mismo grupo exacto no puede jugar mas de {{ $settings['premade_daily_limit'] }} veces al dia como premade.</span>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-pl-bonus">Bonus de puntos al ganar siendo aleatorio (%)</label>
                        <input type="number" min="0" max="50" step="0.1" id="s-pl-bonus" name="random_vs_premade_pl_bonus_pct" value="{{ $settings['random_vs_premade_pl_bonus_pct'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-mmr-bonus">Bonus de MMR al ganar siendo aleatorio (%)</label>
                        <input type="number" min="0" max="50" step="0.1" id="s-mmr-bonus" name="random_vs_premade_mmr_bonus_pct" value="{{ $settings['random_vs_premade_mmr_bonus_pct'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-pl-pen">Recorte de puntos al ganar siendo premade (%)</label>
                        <input type="number" min="0" max="50" step="0.1" id="s-pl-pen" name="premade_vs_random_pl_win_penalty_pct" value="{{ $settings['premade_vs_random_pl_win_penalty_pct'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-mmr-pen">Recorte de MMR al ganar siendo premade (%)</label>
                        <input type="number" min="0" max="50" step="0.1" id="s-mmr-pen" name="premade_vs_random_mmr_win_penalty_pct" value="{{ $settings['premade_vs_random_mmr_win_penalty_pct'] }}" class="ap-input">
                    </div>
                </div>
            </section>

            {{-- Sanciones --}}
            <section class="ap-card ap-rise ap-delay-3 p-4">
                <x-admin.section-head title="Sanciones" icon="ban" tone="danger"
                                        note="Se aplican solas cuando alguien abandona una partida o incumple las reglas de soporte. La confianza baja y el bloqueo crece con la reincidencia, hasta el tope que fijes." />
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="ap-field">
                        <label class="ap-label" for="s-lock-ab">Bloqueo por abandonar (horas)</label>
                        <input type="number" min="1" max="168" id="s-lock-ab" name="abandonment_lock_hours" value="{{ $settings['abandonment_lock_hours'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-lock-sup">Bloqueo por infraccion de soporte (horas)</label>
                        <input type="number" min="1" max="168" id="s-lock-sup" name="support_infraction_lock_hours" value="{{ $settings['support_infraction_lock_hours'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-lock-max">Tope maximo de bloqueo (horas)</label>
                        <input type="number" min="1" max="336" id="s-lock-max" name="penalty_max_lock_hours" value="{{ $settings['penalty_max_lock_hours'] }}" class="ap-input">
                        <span class="ap-hint">Ningun bloqueo automatico pasa de aqui.</span>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-trust-ab">Confianza que resta el abandono</label>
                        <input type="number" min="1" max="100" id="s-trust-ab" name="abandonment_trust_penalty" value="{{ $settings['abandonment_trust_penalty'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-trust-sup">Confianza que resta la infraccion</label>
                        <input type="number" min="1" max="100" id="s-trust-sup" name="support_infraction_trust_penalty" value="{{ $settings['support_infraction_trust_penalty'] }}" class="ap-input">
                    </div>
                </div>
            </section>

            {{-- Textos publicos --}}
            <section class="ap-card ap-rise ap-delay-4 p-4">
                <x-admin.section-head title="Textos del sitio publico" icon="type"
                                        note="Lo que leen los jugadores en la portada. No afecta a las partidas." />
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="ap-field">
                        <label class="ap-label" for="s-season">Nombre de la temporada</label>
                        <input type="text" id="s-season" name="season_name" value="{{ $settings['season_name'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-tagline">Frase de portada</label>
                        <input type="text" id="s-tagline" name="home_tagline" value="{{ $settings['home_tagline'] }}" class="ap-input">
                    </div>
                    <div class="ap-field md:col-span-2">
                        <label class="ap-label" for="s-rules">Resumen de reglas</label>
                        <textarea id="s-rules" name="rules_excerpt" rows="3" class="ap-textarea">{{ $settings['rules_excerpt'] }}</textarea>
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-contact">Contacto de soporte</label>
                        <input type="text" id="s-contact" name="support_contact" value="{{ $settings['support_contact'] }}" class="ap-input">
                    </div>
                    <div class="ap-field">
                        <label class="ap-label" for="s-invite">Enlace de invitacion a Discord</label>
                        <input type="url" id="s-invite" name="discord_invite_url" value="{{ $settings['discord_invite_url'] }}" class="ap-input" placeholder="https://discord.gg/...">
                    </div>
                    <div class="ap-field md:col-span-2">
                        <label class="ap-label" for="s-guild-label">Nombre visible del servidor de Discord</label>
                        <input type="text" id="s-guild-label" name="discord_server_label" value="{{ $settings['discord_server_label'] }}" class="ap-input">
                    </div>
                </div>
            </section>
        </div>

        {{-- Panel lateral: solo lectura. Se separa del formulario para que quede
             claro que aqui no se toca nada; esto vive en el servidor. --}}
        <aside class="ap-card ap-rise ap-delay-2 p-4 xl:sticky" style="top: 76px">
            <x-admin.section-head title="Conexion con Discord" icon="link" tone="info"
                                    note="Solo lectura. Se configura en el servidor, no desde aqui." />

            <div class="flex flex-col gap-2">
                <div class="ap-kv">
                    <span class="ap-kv-key">Token del bot</span>
                    @if($discordConfig['bot_token_configured'])
                        <span class="ap-badge ap-badge-ok"><span class="ap-badge-dot"></span>Configurado</span>
                    @else
                        <span class="ap-badge ap-badge-danger"><span class="ap-badge-dot"></span>Sin configurar</span>
                    @endif
                </div>
                <div class="ap-kv">
                    <span class="ap-kv-key">Servidor (guild)</span>
                    <span class="ap-kv-value">{{ $discordConfig['guild_id'] ?: 'sin valor' }}</span>
                </div>
                <div class="ap-kv">
                    <span class="ap-kv-key">Canal de avisos</span>
                    <span class="ap-kv-value">{{ $discordConfig['alerts_channel_id'] ?: 'sin valor' }}</span>
                </div>
                <div class="ap-kv">
                    <span class="ap-kv-key">Administradores</span>
                    <span class="ap-kv-value">{{ implode(', ', $discordConfig['admin_ids']) ?: 'sin valores' }}</span>
                </div>
            </div>

            <p class="ap-hint mt-3">
                Si algo aqui aparece sin valor, hay que anadirlo al archivo de entorno del servidor
                y volver a cargar la configuracion.
            </p>
        </aside>
    </div>

    {{-- Barra de guardado fija: el formulario es largo y el boton no deberia
         obligar a bajar hasta el final para encontrarlo. --}}
    <div class="ap-savebar">
        <span class="ap-section-note">Los cambios se aplican de inmediato en todo el sitio.</span>
        <button type="submit" class="ap-btn ap-btn-primary">
            <x-admin.icon name="check" class="h-3.5 w-3.5" />
            Guardar cambios
        </button>
    </div>
</form>
@endsection
