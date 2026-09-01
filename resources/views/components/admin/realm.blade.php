@props(['realm' => null])
@php
    $names = ['ignis' => 'Ignis', 'alsius' => 'Alsius', 'syrtis' => 'Syrtis'];
    $key = strtolower((string) $realm);
@endphp
<span {{ $attributes->merge(['class' => 'ap-realm ap-realm-' . ($names[$key] ? $key : 'none')]) }}>
    <span class="ap-realm-mark" aria-hidden="true"></span>{{ $names[$key] ?? ($realm ?: '—') }}
</span>
