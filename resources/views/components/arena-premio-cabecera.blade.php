@props(['premios'])
{{-- La cabecera del premio: la copa, "Premios de la temporada" y lo que se reparte,
     con la gema. La comparten el podio de la portada y la barra del lobby y del
     Salon de la Fama, para que el premio se vea igual en todas partes. --}}
        {{-- El trofeo abre la seccion. Un premio se anuncia con la copa, no con
             una linea de texto en versalitas: es lo que hace que la primera
             pantalla se lea como un torneo y no como una tabla. --}}
        <svg class="arena-podium-cup" viewBox="0 0 48 48" aria-hidden="true">
            <defs>
                <linearGradient id="arenaCupOro" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#ffe9a8" />
                    <stop offset=".45" stop-color="#e3b75c" />
                    <stop offset="1" stop-color="#9d7526" />
                </linearGradient>
            </defs>
            {{-- Las asas --}}
            <path d="M13 12H8a6 6 0 0 0 6 10M35 12h5a6 6 0 0 1-6 10"
                  fill="none" stroke="url(#arenaCupOro)" stroke-width="2.6" stroke-linecap="round" />
            {{-- La copa --}}
            <path d="M13 8h22v11a11 11 0 0 1-22 0z" fill="url(#arenaCupOro)" />
            {{-- El pie --}}
            <path d="M22 30h4v6h-4z" fill="url(#arenaCupOro)" />
            <path d="M15 39h18a1.6 1.6 0 0 1 1.6 1.6V42H13.4v-1.4A1.6 1.6 0 0 1 15 39z" fill="url(#arenaCupOro)" />
            {{-- El brillo, que es lo que lo hace parecer metal y no una silueta --}}
            <path d="M17 10h3v9a7 7 0 0 0 2 4.9A8 8 0 0 1 17 17z" fill="#fff6dc" opacity=".55" />
        </svg>

        <p class="arena-kicker">Premios de la temporada</p>
        <h2 id="arenaPodiumTitle" class="arena-podium-title">
            {{-- Sin `lazy`: esta en la primera pantalla de la portada, asi que
                 diferirla solo retrasa lo que hay que ver primero. --}}
            <img src="{{ asset('images/magnanita.webp') }}?v={{ @filemtime(public_path('images/magnanita.webp')) ?: '1' }}"
                 alt="" class="arena-podium-gema" width="256" height="208" fetchpriority="high" decoding="async">
            <span>
                <span class="arena-podium-total">{{ $premios->total() }}</span>
                {{ $premios->moneda() }}
            </span>
        </h2>
        <p class="arena-podium-note">{{ $premios->bases() }}</p>

