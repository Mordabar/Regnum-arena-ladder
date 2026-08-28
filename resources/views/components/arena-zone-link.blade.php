@props(['zone'])

@php
    $zoneKey = $zone instanceof \App\Models\ArenaMatch ? $zone->zone_key : \App\Models\ArenaMatch::normalizeZoneKey($zone);
    $zoneName = $zone instanceof \App\Models\ArenaMatch ? $zone->zone_name : \App\Models\ArenaMatch::zoneLabel($zone);
    
    // Fallback si no hay zona válida
    if (!$zoneKey) {
        echo '<span class="text-[color:var(--arena-muted)] text-sm italic">' . ($zoneName ?? 'Sin zona') . '</span>';
        return;
    }
    
    // Extraer solo "ZONA 1" del texto
    $shortName = explode(' - ', $zoneName)[0] ?? $zoneName;
@endphp

<button type="button" 
        @click.stop="$dispatch('open-map-modal', { zone: '{{ $zoneKey }}' })"
        class="inline-flex items-center gap-1.5 rounded-md bg-[rgba(216,177,92,0.1)] px-2 py-1 text-xs font-semibold text-[color:var(--arena-gold)] shadow-sm ring-1 ring-inset ring-[color:var(--arena-line)] hover:bg-[rgba(216,177,92,0.2)] hover:text-white transition-all group font-[Cinzel]"
        title="Ver {{ $zoneName }} en el mapa táctico">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 text-[color:var(--arena-muted)] group-hover:text-white transition-colors">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z" />
    </svg>
    {{ $shortName }}
</button>
