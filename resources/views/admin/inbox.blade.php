@extends('layouts.admin')

@section('title', 'Moderacion')
@section('page-title', 'Bandeja de moderacion')
@section('page-subtitle', 'Casos que no se resuelven solos y esperan a una persona')

@section('page-actions')
    <a href="{{ route('admin.matches.index') }}" class="ap-btn ap-btn-sm ap-btn-quiet">Ver todos los enfrentamientos</a>
@endsection

@section('content')
@php
    // Una sola lista de trabajo en vez de dos columnas: el moderador baja de
    // arriba a abajo y termina. Las disputas van primero porque estan
    // bloqueadas de verdad; las confirmaciones aun pueden resolverse solas
    // cuando el rival responde, y se ordenan por lo que vence antes.
    $items = collect();

    foreach ($disputedMatches as $match) {
        $items->push([
            'kind' => 'dispute',
            'match' => $match,
            'report' => $match->report,
            'deadline' => null,
        ]);
    }

    foreach ($pendingConfirmations as $report) {
        if (!$report->match) {
            continue;
        }
        $items->push([
            'kind' => 'confirmation',
            'match' => $report->match,
            'report' => $report,
            'deadline' => $report->match->expires_at,
        ]);
    }

    // Los avisos de abandono no cambian el estado del enfrentamiento -hacerlo
    // dejaba anular una derrota con un boton-, asi que no llegan por la via de
    // 'disputed'. Sin listarlos aqui, existian y nadie se enteraba.
    foreach ($pendingAbandonments ?? [] as $aviso) {
        if (!$aviso->match) {
            continue;
        }
        $items->push([
            'kind' => 'abandonment',
            'match' => $aviso->match,
            'report' => null,
            'aviso' => $aviso,
            'deadline' => null,
        ]);
    }

    $disputeCount = $disputedMatches->count();
    $confirmationCount = $pendingConfirmations->count();
    $abandonmentCount = collect($pendingAbandonments ?? [])->filter(fn ($a) => (bool) $a->match)->count();
@endphp

<div class="ap-rise mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar casos">
    <button type="button" class="ap-btn ap-btn-sm ap-btn-primary" data-ap-filter="all" aria-pressed="true">
        Todo <span class="ap-num">{{ $items->count() }}</span>
    </button>
    <button type="button" class="ap-btn ap-btn-sm" data-ap-filter="dispute" aria-pressed="false">
        En disputa <span class="ap-num">{{ $disputeCount }}</span>
    </button>
    <button type="button" class="ap-btn ap-btn-sm" data-ap-filter="confirmation" aria-pressed="false">
        Sin confirmar <span class="ap-num">{{ $confirmationCount }}</span>
    </button>
    <button type="button" class="ap-btn ap-btn-sm" data-ap-filter="abandonment" aria-pressed="false">
        Abandonos <span class="ap-num">{{ $abandonmentCount }}</span>
    </button>
</div>

<div class="ap-rise ap-delay-1 flex flex-col gap-3" id="ap-worklist">
    @forelse($items as $item)
        @php
            $match = $item['match'];
            $report = $item['report'];
            $isDispute = $item['kind'] === 'dispute';
            $esAbandono = $item['kind'] === 'abandonment';
            $aviso = $item['aviso'] ?? null;
        @endphp
        <article class="ap-card p-4" data-ap-kind="{{ $item['kind'] }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[13.5px] font-semibold">{{ $match->match_code }}</span>
                        <x-admin.mode :mode="$match->arena_mode" />
                        @if($esAbandono)
                            <span class="ap-badge ap-badge-warn">Aviso de abandono</span>
                        @else
                            <x-admin.status :value="$isDispute ? 'disputed' : 'pending_confirmation'" :kind="$isDispute ? 'match' : 'report'" />
                        @endif
                    </div>

                    <p class="ap-section-note mt-1.5">
                        <x-admin.realm :realm="$match->team_a_realm" /> contra <x-admin.realm :realm="$match->team_b_realm" />
                        · {{ $match->zone_name }}
                        @if($esAbandono)
                            · {{ $aviso->reporter?->character_name ?? 'jugador eliminado' }}
                            señala a {{ $aviso->accused?->character_name ?? 'jugador eliminado' }}
                            <x-admin.ago :date="$aviso->created_at" empty="" />
                        @else
                            · reportado por {{ $report?->reporter?->character_name ?? 'jugador eliminado' }}
                            <x-admin.ago :date="$report?->created_at" empty="" />
                        @endif
                    </p>

                    @if($esAbandono)
                        <p class="ap-section-note mt-2" style="color: var(--ap-text-muted)">
                            El combate sigue su curso; esto no lo bloquea. Nadie sera sancionado hasta que lo mires.
                        </p>
                        @if($aviso->note)
                            <p class="ap-quote mt-2">“{{ \Illuminate\Support\Str::limit($aviso->note, 160) }}”</p>
                        @endif
                    @elseif($isDispute)
                        <p class="ap-section-note mt-2" style="color: var(--ap-text-muted)">
                            El rival rechazo el resultado. Nadie mas puede desbloquearlo: hay que decidir aqui.
                        </p>
                        @if($report?->reporter_note)
                            <p class="ap-quote mt-2">“{{ \Illuminate\Support\Str::limit($report->reporter_note, 160) }}”</p>
                        @endif
                    @elseif($item['deadline'])
                        <p class="ap-section-note mt-2" style="color: var(--ap-text-muted)">
                            Si el rival no responde, se resuelve solo <x-admin.ago :date="$item['deadline']" />.
                        </p>
                    @endif
                </div>

                <a href="{{ route('admin.matches.show', $match) }}" class="ap-btn {{ $isDispute ? 'ap-btn-primary' : '' }}">
                    {{ $isDispute || $esAbandono ? 'Resolver' : 'Revisar' }}
                    <x-admin.icon name="arrow-right" class="h-3.5 w-3.5" />
                </a>
            </div>
        </article>
    @empty
        <div class="ap-card">
            <div class="ap-empty">
                <x-admin.icon name="check" class="h-7 w-7" style="color: var(--ap-ok)" />
                <p class="m-0 text-[13.5px]" style="color: var(--ap-text)">La bandeja esta vacia</p>
                <p class="m-0">No hay reportes sin confirmar, disputas abiertas ni avisos de abandono.</p>
            </div>
        </div>
    @endforelse

    <div class="ap-card" id="ap-worklist-empty" hidden>
        <div class="ap-empty">
            <x-admin.icon name="check" class="h-7 w-7" />
            <p class="m-0">Ningun caso en esta categoria.</p>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Filtro en el cliente: la lista completa ya viene cargada, asi que
    // cambiar de categoria no deberia costar una recarga.
    (function () {
        const buttons = document.querySelectorAll('[data-ap-filter]');
        const cards = document.querySelectorAll('#ap-worklist [data-ap-kind]');
        const empty = document.getElementById('ap-worklist-empty');
        if (!buttons.length) return;

        buttons.forEach((button) => button.addEventListener('click', () => {
            const wanted = button.dataset.apFilter;
            let visible = 0;

            buttons.forEach((other) => {
                const active = other === button;
                other.setAttribute('aria-pressed', active ? 'true' : 'false');
                other.classList.toggle('ap-btn-primary', active);
            });

            cards.forEach((card) => {
                const show = wanted === 'all' || card.dataset.apKind === wanted;
                card.hidden = !show;
                if (show) visible++;
            });

            if (empty) empty.hidden = visible > 0 || cards.length === 0;
        }));
    })();
</script>
@endpush
@endsection
