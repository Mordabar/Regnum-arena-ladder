@props(['value' => null, 'kind' => 'match'])
@php
    // El color nunca va solo: la insignia siempre lleva texto. Un moderador
    // daltonico tiene que poder distinguir "En disputa" de "Completado".
    $labels = $kind === 'report'
        ? \App\Models\MatchReport::STATUSES
        : \App\Models\ArenaMatch::STATUSES;

    $tones = [
        'pending_acceptance' => 'ap-badge-info',
        'in_progress' => 'ap-badge-warn',
        'completed' => 'ap-badge-ok',
        'cancelled' => 'ap-badge-neutral',
        'void' => 'ap-badge-neutral',
        'voided' => 'ap-badge-neutral',
        'disputed' => 'ap-badge-danger',
        'pending_confirmation' => 'ap-badge-info',
        'confirmed' => 'ap-badge-ok',
        'rejected' => 'ap-badge-danger',
        'admin_resolved' => 'ap-badge-ok',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'ap-badge ' . ($tones[$value] ?? 'ap-badge-neutral')]) }}>
    <span class="ap-badge-dot" aria-hidden="true"></span>{{ $labels[$value] ?? $value ?? '—' }}
</span>
