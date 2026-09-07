{{-- Paginacion del sitio publico.

     La de Tailwind que trae Laravel viene con su propia paleta y sus variantes
     oscuras, asi que en el ladder los numeros salian pegados unos a otros y con
     colores que no son los del arena. --}}
@if ($paginator->hasPages())
    <nav class="arena-pagination" role="navigation" aria-label="Paginacion">
        <p class="arena-pagination-count">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </p>

        <div class="arena-pagination-pages">
            @if ($paginator->onFirstPage())
                <span class="arena-pagination-link is-disabled" aria-disabled="true">Anterior</span>
            @else
                <a class="arena-pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="arena-pagination-gap" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="arena-pagination-link is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="arena-pagination-link" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="arena-pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a>
            @else
                <span class="arena-pagination-link is-disabled" aria-disabled="true">Siguiente</span>
            @endif
        </div>
    </nav>
@endif
