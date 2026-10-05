@php
    use App\Support\I18n\Idioma;

    $actual = Idioma::actual();
    $idiomas = Idioma::IDIOMAS;

    // El mismo sitio en otro idioma: la direccion de la pagina con ?lang=xx.
    $enlace = fn (string $codigo) => request()->fullUrlWithQuery(['lang' => $codigo]);
@endphp

{{-- El selector de idioma.

     Un boton flotante abajo a la izquierda, en movil y en escritorio, siempre en
     el mismo sitio: quien llega en un idioma que no entiende lo busca en una
     esquina y tiene que encontrarlo sin leer nada, por eso lleva la bandera.
     Pero una bandera no es un idioma -el portugues de Brasil, el ingles de
     cualquier sitio-, asi que al abrirse cada fila dice el nombre del idioma
     en el propio idioma. Los enlaces son de verdad (?lang=), de modo que
     funciona sin JavaScript y los buscadores ven todas las versiones. --}}
<x-arena-banderas />

<div class="arena-lang" data-lang-root translate="no">
    <button type="button" class="arena-lang-fab" data-lang-toggle
            aria-expanded="false" aria-controls="arenaLangSheet"
            aria-label="{{ __('Idioma') }}: {{ $idiomas[$actual]['nombre'] }}">
        <svg class="arena-flag" viewBox="0 0 36 24" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#flag-{{ $actual }}"/></svg>
        <span class="arena-lang-code">{{ $idiomas[$actual]['corto'] }}</span>
        <svg class="arena-lang-chev" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5-5 5 5"/></svg>
    </button>

    <div class="arena-lang-sheet" id="arenaLangSheet" data-lang-sheet hidden>
        <p class="arena-lang-title" translate="yes">{{ __('Idioma') }}</p>
        <ul class="arena-lang-list">
            @foreach($idiomas as $codigo => $datos)
                <li>
                    <a href="{{ $enlace($codigo) }}" hreflang="{{ $datos['html'] }}" lang="{{ $datos['html'] }}"
                       class="arena-lang-item {{ $codigo === $actual ? 'is-actual' : '' }}"
                       @if($codigo === $actual) aria-current="true" @endif>
                        <svg class="arena-flag" viewBox="0 0 36 24" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><use href="#flag-{{ $codigo }}"/></svg>
                        <span class="arena-lang-name">{{ $datos['nombre'] }}</span>
                        <svg class="arena-lang-check" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</div>

<script>
    (function () {
        var raiz = document.querySelector('[data-lang-root]');
        if (!raiz) { return; }
        var boton = raiz.querySelector('[data-lang-toggle]');
        var hoja = raiz.querySelector('[data-lang-sheet]');

        function abrir(si) {
            hoja.hidden = !si;
            raiz.classList.toggle('is-abierto', si);
            boton.setAttribute('aria-expanded', si ? 'true' : 'false');
            if (si) {
                var actual = hoja.querySelector('[aria-current]') || hoja.querySelector('a');
                if (actual) { actual.focus({ preventScroll: true }); }
            }
        }

        // Flotando sobre el contenido tapa botones y texto: se esconde al bajar
        // y vuelve al subir, al llegar arriba o con el foco del teclado.
        var ultimo = window.scrollY, ticking = false;
        function vigilar() {
            ticking = false;
            var y = window.scrollY, abierto = !hoja.hidden;
            if (!abierto) {
                if (y > ultimo + 6 && y > 80) { raiz.classList.add('is-oculto'); }
                else if (y < ultimo - 6 || y <= 80) { raiz.classList.remove('is-oculto'); }
            }
            ultimo = y;
        }
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(vigilar); }
        }, { passive: true });
        raiz.addEventListener('focusin', function () { raiz.classList.remove('is-oculto'); });

        boton.addEventListener('click', function () { abrir(hoja.hidden); });

        document.addEventListener('click', function (e) {
            if (!hoja.hidden && !raiz.contains(e.target)) { abrir(false); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !hoja.hidden) { abrir(false); boton.focus(); }
            // Tabular desde el ultimo idioma saca el foco de la hoja: se cierra.
            if (e.key === 'Tab' && !e.shiftKey && !hoja.hidden) {
                var enlaces = hoja.querySelectorAll('a');
                if (document.activeElement === enlaces[enlaces.length - 1]) { abrir(false); }
            }
        });
    })();
</script>
