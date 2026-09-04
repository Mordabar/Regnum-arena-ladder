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
                    Invitacion a party {{ \App\Support\ArenaMode::label($invite->party->arena_mode) }}
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
                <p class="arena-invite-body">
                    <b>{{ $invite->party->leader?->character_name ?? 'Un jugador' }}</b>
                    invita a tu <b>{{ $invite->player->character_name }}</b>
                    a jugar {{ \App\Support\ArenaMode::label($invite->party->arena_mode) }}.
                </p>

                <div class="arena-invite-actions">
                    <form method="POST" action="{{ route('party.accept', ['party' => $invite->party_id, 'member' => $invite->id]) }}">
                        @csrf
                        <button type="submit" class="arena-btn-safe px-4 py-2 text-sm">Aceptar</button>
                    </form>
                    <form method="POST" action="{{ route('party.reject', ['party' => $invite->party_id, 'member' => $invite->id]) }}">
                        @csrf
                        <button type="submit" class="arena-btn-danger-ghost px-4 py-2 text-sm">Rechazar</button>
                    </form>
                </div>
            </div>

            {{-- Lo que queda a la vista cuando esta plegada: sigue diciendo que
                 hay algo pendiente, y se vuelve a abrir pulsandola. --}}
            <button type="button" class="arena-invite-folded" data-invite-unfold hidden>
                Tienes una invitacion sin contestar. Abrir
            </button>
        </article>
    @endforeach
</div>
