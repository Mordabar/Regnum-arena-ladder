@extends('layouts.arena')

@section('title', 'Explorador de Zonas - Regnum Arena')

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <style>
        #arena-map-container {
            width: 100%;
            height: calc(100vh - 12rem);
            min-height: 500px;
            background-color: #050608;
            border: 1px solid var(--arena-line);
            border-radius: 0.5rem;
            box-shadow: var(--arena-shadow);
            z-index: 10;
        }

        .leaflet-popup-content-wrapper { background-color: var(--arena-panel-strong); border: 1px solid var(--arena-line); color: var(--arena-text); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.8); }
        .leaflet-popup-tip { background-color: var(--arena-panel-strong); border: 1px solid var(--arena-line); }
        .leaflet-popup-content { margin: 12px; }
        
        .zone-title { font-family: 'Cinzel', serif; color: var(--arena-gold); font-size: 16px; font-weight: 700; margin: 0 0 5px 0; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 5px; }
        .zone-badge { display: inline-block; background-color: rgba(216, 177, 92, 0.15); color: var(--arena-gold); border: 1px solid var(--arena-line); padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px; }

        .zone-label-permanent { 
            background: rgba(12, 8, 6, 0.85); 
            border: 1px solid rgba(216, 177, 92, 0.5); 
            border-radius: 4px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.9); 
            color: var(--arena-gold); 
            font-family: 'Cinzel', serif; 
            font-weight: 700; 
            text-align: center; 
            transition: font-size 0.2s, padding 0.2s; 
            letter-spacing: 1px;
            backdrop-filter: blur(2px);
        }
        .leaflet-tooltip-left.zone-label-permanent::before, .leaflet-tooltip-right.zone-label-permanent::before, .leaflet-tooltip-top.zone-label-permanent::before, .leaflet-tooltip-bottom.zone-label-permanent::before { display: none; }

        #arena-map-container[data-zoom="-1"] .zone-label-permanent { font-size: 7px; padding: 2px 4px; }
        #arena-map-container[data-zoom="0"] .zone-label-permanent { font-size: 10px; padding: 3px 6px; }
        #arena-map-container[data-zoom="1"] .zone-label-permanent { font-size: 15px; padding: 4px 8px; }
        #arena-map-container[data-zoom="2"] .zone-label-permanent { font-size: 24px; padding: 6px 14px; }
        #arena-map-container[data-zoom="3"] .zone-label-permanent { font-size: 36px; padding: 10px 20px; }

        .leaflet-interactive { transition: fill-opacity 0.2s, stroke-width 0.2s; }
    </style>
@endpush

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 w-full mt-4">
    <div class="mb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold font-[Cinzel] text-white flex items-center gap-3">
                <span class="text-3xl">🗺️</span> Atlas de Cacería
            </h1>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] max-w-2xl">
                Explora el territorio de conflicto activo de la Arena. Haz clic en las zonas para ver sus detalles estratégicos antes de entrar en cola.
            </p>
        </div>
        <div>
            <a href="{{ route('lobby') }}" class="inline-flex items-center justify-center rounded-md border border-[color:var(--arena-gold)] bg-transparent px-4 py-2 text-sm font-medium text-[color:var(--arena-gold)] hover:bg-[color:var(--arena-gold)] hover:text-black transition-colors shadow-[0_0_15px_rgba(216,177,92,0.15)] ring-1 ring-inset ring-[color:var(--arena-gold)] ring-opacity-20 font-[Cinzel]">
                Volver al Cuartel
            </a>
        </div>
    </div>

    <!-- Contenedor del Mapa -->
    <div class="relative w-full rounded-xl overflow-hidden border border-[color:var(--arena-line)] ring-1 ring-white/5">
        <div id="arena-map-container" class="z-0"></div>
        <div class="absolute inset-x-0 bottom-0 h-12 bg-gradient-to-t from-[#090706] to-transparent pointer-events-none z-10"></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('js/arena-zones.js') }}"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof L === 'undefined') return;

        const w = 1086, h = 1086;
        const mapContainer = document.getElementById('arena-map-container');
        
        const map = L.map('arena-map-container', { 
            crs: L.CRS.Simple, 
            minZoom: -1, 
            maxZoom: 3, 
            zoomControl: false 
        });
        
        const bounds = [[0, 0], [h, w]];
        
        // Carga la imagen limpia que nos dejamos en public/mapa
        L.imageOverlay('{{ asset("mapa/mapa-regnum-limpio.jpg") }}', bounds).addTo(map);
        map.fitBounds(bounds);
        
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        if (typeof PREDEFINED_ZONES_JSON !== 'undefined') {
            PREDEFINED_ZONES_JSON.forEach(zone => {
                if (!zone.coords || zone.coords.length < 3) return;

                let polygon = L.polygon(zone.coords, {
                    color: 'rgba(216, 177, 92, 0.6)', 
                    weight: 2, 
                    fillColor: '#D8B15C', 
                    fillOpacity: 0.08,
                    dashArray: '5 5'
                }).addTo(map);

                polygon.on('mouseover', function () { this.setStyle({ fillOpacity: 0.25, weight: 3, color: '#F9D87E' }); });
                polygon.on('mouseout', function () { this.setStyle({ fillOpacity: 0.08, weight: 2, color: 'rgba(216, 177, 92, 0.6)' }); });

                let shortName = zone.name.split(' - ')[0].toUpperCase();
                polygon.bindTooltip(shortName, {
                    permanent: true,
                    direction: 'center',
                    className: 'zone-label-permanent'
                });

                polygon.bindPopup(`
                    <div class="zone-badge">PvP Activo</div>
                    <h4 class="zone-title">${zone.name}</h4>
                    <span style="font-size:10px; color:var(--arena-muted)">Identifier: ${zone.key}</span>
                `);
            });
        }

        // Rescalado orgánico con Tailwind / CSS vars
        mapContainer.setAttribute('data-zoom', map.getZoom());
        map.on('zoomend', function() {
            mapContainer.setAttribute('data-zoom', map.getZoom());
        });
    });
</script>
@endpush
