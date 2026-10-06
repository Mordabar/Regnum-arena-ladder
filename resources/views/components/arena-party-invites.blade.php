@props(['invites'])

{{-- Invitaciones a party, flotando sobre la pagina.

     Dentro del bloque de la cola se perdian: hay que estar mirando esa parte
     de la pantalla justo cuando llegan, y en movil quedaban a un scroll de
     distancia. Aqui aparecen encima de todo, digan lo que digan los paneles de
     debajo, y cada una dice a que modalidad te invitan y con que personaje
     tuyo, que antes tampoco se veia.

     El contenedor se pinta siempre, aunque este vacio: el sondeo cambia lo de
     dentro cuando llega una invitacion nueva, y sin un hueco donde ponerla no
     aparecia hasta recargar la pagina. --}}
<div class="arena-invites" data-console-invites role="region" aria-label="Invitaciones a party" @if($invites->isEmpty()) hidden @endif>
    @foreach($invites as $invite)
        <article class="arena-invite" data-arena-invite data-invite-id="{{ $invite->id }}">
            <header>
                <span class="arena-invite-kicker">
                    <x-admin.icon name="inbox" class="h-3.5 w-3.5" />
                    {{ __('Invitacion a party :modo · :tipo', ['modo' => \App\Support\ArenaMode::label($invite->party->arena_mode), 'tipo' => __(\App\Support\Competition::label((bool) $invite->party->is_ranked))]) }}
                </span>
                {{-- Plegar, no descartar. La aspa de antes borraba la tarjeta y
                     dejaba la invitacion viva en el servidor: el jugador se
                     quedaba sin poder contestarla y, de paso, sin poder recibir
                     ni armar otra party hasta recargar. --}}
                <button type="button" class="arena-invite-hide" data-invite-fold aria-label="Plegar la invitacion" aria-expanded="true">
                    <x-admin.icon name="close" class="h-4 w-4" />
                </button>
            </header>

            <div data-invite-detail>
                @php
                    $invLider = $invite->party->leader?->character_name
                        ? '<b><span translate="no">' . e($invite->party->leader->character_name) . '</span></b>'
                        : '<b>' . e(__('Un jugador')) . '</b>';
                    $invPersonaje = '<b><span translate="no">' . e($invite->player->character_name) . '</span></b>';
                    $invModo = \App\Support\ArenaMode::label($invite->party->arena_mode);
                    // En aleman los sustantivos van con mayuscula; en el resto, en minuscula.
                    $invTipo = __(\App\Support\Competition::label((bool) $invite->party->is_ranked));
                    if (app()->getLocale() !== 'de') { $invTipo = mb_strtolower($invTipo); }
                @endphp
                <p class="arena-invite-body">
                    {!! __(':lider invita a tu :personaje a jugar :modo :tipo.', ['lider' => $invLider, 'personaje' => $invPersonaje, 'modo' => e($invModo), 'tipo' => e($invTipo)]) !!}
                </p>

                <div class="arena-invite-actions">
                    <form method="POST" action="{{ route('party.accept', ['party' => $invite->party_id, 'member' => $invite->id]) }}" data-sin-recarga>
                        @csrf
                        <button type="submit" class="arena-btn-safe px-4 py-2 text-sm"><x-arena-icon name="check" class="h-4 w-4 shrink-0" />{{ __('Aceptar') }}</button>
                    </form>
                    <form method="POST" action="{{ route('party.reject', ['party' => $invite->party_id, 'member' => $invite->id]) }}" data-sin-recarga>
                        @csrf
                        <button type="submit" class="arena-btn-danger-ghost px-4 py-2 text-sm"><x-arena-icon name="x" class="h-4 w-4 shrink-0" />Rechazar</button>
                    </form>
                </div>
            </div>

            {{-- Lo que queda a la vista cuando esta plegada: sigue diciendo que
                 hay algo pendiente, y se vuelve a abrir pulsandola. --}}
            <button type="button" class="arena-invite-folded" data-invite-unfold hidden>
                {{ __('Tienes una invitacion sin contestar: :modo · :tipo. Abrir', ['modo' => $invModo, 'tipo' => $invTipo]) }}
            </button>
        </article>
    @endforeach
</div>
