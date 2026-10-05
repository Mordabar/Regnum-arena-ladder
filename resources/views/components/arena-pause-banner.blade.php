@props(['cta' => 'lobby'])
@php
    use App\Models\ArenaSeason;
    use App\Support\Competition;

    // El ladder esta en pausa cuando no hay temporada en juego. Solo se pinta
    // entonces: con una temporada abierta no hay nada que avisar.
    $enPausa = !Competition::rankedOpen();
    $ultima = $enPausa && \Illuminate\Support\Facades\Schema::hasTable('arena_seasons')
        ? ArenaSeason::query()->where('status', ArenaSeason::STATUS_ARCHIVED)->orderByDesc('ends_at')->orderByDesc('id')->first()
        : null;
    $amistosos = Competition::friendlyEnabled();
@endphp

@if($enPausa)
    <aside class="arena-pause-banner" role="status">
        <span class="arena-pause-banner-icon" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
        </span>
        <div class="min-w-0">
            <b>Ladder en pausa</b>
            <span class="t">
                @if($ultima)
                    @if($ultima->ends_at)
                        {{ __(':name terminó el :fecha.', ['name' => $ultima->name, 'fecha' => ArenaSeason::fechaCorta($ultima->ends_at)]) }}
                    @else
                        {{ __(':name terminó.', ['name' => $ultima->name]) }}
                    @endif
                @else
                    No hay ninguna temporada en juego.
                @endif
                @if($amistosos)
                    Los combates son amistosos: se juegan igual, pero no mueven el ranking.
                @else
                    Por ahora no hay colas abiertas.
                @endif
            </span>
        </div>
        @if($cta === 'hall' || !$amistosos)
            <a href="{{ route('hall-of-fame') }}" class="arena-btn-ghost cta px-4 py-2 text-sm">Ver el podio final</a>
        @else
            <a href="{{ route('lobby', ['kind' => 'friendly']) }}" class="arena-btn cta px-4 py-2 text-sm">Jugar amistoso</a>
        @endif
    </aside>
@endif
