@props(['invites'])

{{-- Invitaciones a party, flotando sobre la pagina.

     Dentro del bloque de la cola se perdian: hay que estar mirando esa parte
     de la pantalla justo cuando llegan, y en movil quedaban a un scroll de
     distancia. Aqui aparecen encima de todo, digan lo que digan los paneles de
     debajo, y cada una dice a que modalidad te invitan y con que personaje
     tuyo, que antes tampoco se veia. --}}
@if($invites->isNotEmpty())
    <div class="arena-invites" role="region" aria-label="Invitaciones a party">
        @foreach($invites as $invite)
            <article class="arena-invite" data-arena-invite>
                <header>
                    <span class="arena-invite-kicker">
                        <x-admin.icon name="inbox" class="h-3.5 w-3.5" />
                        Invitacion a party {{ \App\Support\ArenaMode::label($invite->party->arena_mode) }}
                    </span>
                    <button type="button" class="arena-invite-hide" data-invite-hide aria-label="Ocultar por ahora">
                        <x-admin.icon name="close" class="h-4 w-4" />
                    </button>
                </header>

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
            </article>
        @endforeach
    </div>

    @push('scripts')
    <script>
        /* Ocultar una invitacion no la contesta: solo la quita de la vista
           hasta la siguiente carga. Contestar es aceptar o rechazar. */
        document.addEventListener('click', function (event) {
            var hide = event.target.closest('[data-invite-hide]');
            if (!hide) { return; }

            var card = hide.closest('[data-arena-invite]');
            if (card) { card.remove(); }
        });
    </script>
    @endpush
@endif
