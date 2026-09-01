@if ($paginator->hasPages())
    <nav class="ap-pagination" role="navigation" aria-label="Paginacion">
        <span class="ap-pagination-info">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}
        </span>

        <div class="ap-pagination-controls">
            @if ($paginator->onFirstPage())
                <span class="ap-btn ap-btn-sm" aria-disabled="true">Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="ap-btn ap-btn-sm" rel="prev">Anterior</a>
            @endif

            <span class="ap-pagination-info">Pagina {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="ap-btn ap-btn-sm" rel="next">Siguiente</a>
            @else
                <span class="ap-btn ap-btn-sm" aria-disabled="true">Siguiente</span>
            @endif
        </div>
    </nav>
@endif
