@extends('layouts.arena')

@section('title', 'Admin - Editor de Zonas de Mapa')

@push('arena-map-styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<style>
    /* Popups */
    .leaflet-popup-content-wrapper { background-color: var(--arena-panel); border: 1px solid rgba(216, 177, 92, 0.3); color: #fff; border-radius: 8px; }
    .leaflet-popup-tip { background-color: var(--arena-panel); border: 1px solid rgba(216, 177, 92, 0.3); }
    
    .zone-title { font-family: 'Cinzel', serif; color: var(--arena-gold); margin: 0 0 5px 0; }
    .zone-badge { display: inline-block; background-color: rgba(239, 68, 68, 0.15); color: #fca5a5; padding: 2px 8px; border-radius: 99px; font-size: 10px; margin-bottom: 5px;}

    /* Etiquetas de Texto Permanentes en Zonas (Estilo Gold Arena) */
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
    .leaflet-tooltip-left.zone-label-permanent::before, 
    .leaflet-tooltip-right.zone-label-permanent::before, 
    .leaflet-tooltip-top.zone-label-permanent::before, 
    .leaflet-tooltip-bottom.zone-label-permanent::before { display: none; }

    /* Escala dinámica del texto según el Zoom */
    #admin-map-container[data-zoom="-1"] .zone-label-permanent { font-size: 7px; padding: 2px 4px; }
    #admin-map-container[data-zoom="0"] .zone-label-permanent { font-size: 10px; padding: 3px 6px; }
    #admin-map-container[data-zoom="1"] .zone-label-permanent { font-size: 15px; padding: 4px 8px; }
    #admin-map-container[data-zoom="2"] .zone-label-permanent { font-size: 24px; padding: 6px 14px; }
    #admin-map-container[data-zoom="3"] .zone-label-permanent { font-size: 36px; padding: 10px 20px; }

    .leaflet-interactive { transition: fill-opacity 0.2s, stroke-width 0.2s; }
</style>
@endpush

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <x-arena-breadcrumbs :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Editor de Zonas PvP'],
    ]" class="mb-6" />

    <section class="arena-panel-strong mb-8 p-6 md:p-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="arena-kicker">Mapa Global</p>
            <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Editor de Zonas</h1>
            <p class="mt-2 text-sm text-[color:var(--arena-muted)] max-w-xl">
                Al guardar, esto sobrescribirá el archivo de configuración <code>arena-zones.js</code> afectando a todos los usuarios.
            </p>
        </div>
        
        <form method="POST" action="{{ route('admin.zones.save') }}" class="flex items-center gap-3" id="save-zones-form">
            @csrf
            <input type="hidden" name="zones_json" id="zones-json-input">
            <button type="button" onclick="window.location.reload();" class="arena-btn-secondary px-4 py-2">
                Descartar cambios
            </button>
            <button type="button" id="btn-save-db" class="arena-btn-safe px-5 py-2 flex items-center gap-2">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                Guardar Zonas Oficialmente
            </button>
        </form>
    </section>

    <div class="grid lg:grid-cols-[1fr_320px] gap-6">
        <!-- Mapa Container -->
        <article class="arena-panel p-2 relative h-[700px] overflow-hidden">
            <div id="admin-map-container" class="w-full h-full rounded-xl" style="background-color: #050608;"></div>
            
            <div id="tracing-indicator" class="hidden absolute top-6 left-1/2 -translate-x-1/2 bg-red-600/90 text-white font-bold tracking-widest uppercase text-sm px-4 py-2 rounded shadow-lg animate-pulse" style="z-index: 1000">
                ✏️ MODO DIBUJO ACTIVO (Haz clics en el mapa)
            </div>
        </article>

        <!-- Editor Controls -->
        <article class="arena-panel p-6 self-start">
            <h3 class="text-xl font-semibold text-white mb-4 font-['Cinzel'] text-[color:var(--arena-gold)] border-b border-[color:var(--arena-line)] pb-4">
                Herramientas
            </h3>
            
            <div class="mb-6">
                <label class="block text-sm text-[color:var(--arena-muted)] mb-2">Selecciona la zona a editar:</label>
                <select id="zone-selector" class="arena-select w-full"></select>
            </div>

            <div id="action-buttons" class="space-y-3">
                <button id="btn-draw" class="arena-btn-danger w-full justify-center">
                    ✏️ Redibujar polígono
                </button>
                <div class="my-4 border-t border-[color:var(--arena-line)]"></div>
                <p class="text-xs text-[color:var(--arena-muted)] opacity-70">
                    Al terminar, recuerda dar clic en el botón superior "Guardar Zonas Oficialmente".
                </p>
            </div>

            <div id="trace-buttons" class="hidden space-y-3">
                <button id="btn-finish" class="arena-btn-safe w-full justify-center">
                    ✅ Confirmar Forma
                </button>
                <button id="btn-cancel" class="arena-btn-secondary w-full justify-center">
                    ❌ Cancelar trazado
                </button>
            </div>
        </article>
    </div>
</div>
@endsection

@push('arena-map-scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('js/arena-zones.js') }}?v={{ time() }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const w = 1086, h = 1086;
        const map = L.map('admin-map-container', { crs: L.CRS.Simple, minZoom: -1, maxZoom: 3, zoomControl: false });
        const bounds = [[0, 0], [h, w]];
        L.imageOverlay('{{ asset("mapa/mapa-regnum-limpio.jpg") }}', bounds).addTo(map);
        map.fitBounds(bounds);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        let zonesData = window.ARENA_ZONES_CONFIG ? JSON.parse(JSON.stringify(window.ARENA_ZONES_CONFIG)) : [];
        let drawnLayers = {};
        
        let isTracing = false;
        let currentEditingId = null;
        let tracePoints = [];
        let tempPolyLine = null;

        const selector = document.getElementById('zone-selector');
        const btnDraw = document.getElementById('btn-draw');
        const actionGroup = document.getElementById('action-buttons');
        const traceGroup = document.getElementById('trace-buttons');
        const indicator = document.getElementById('tracing-indicator');
        const saveForm = document.getElementById('save-zones-form');
        const inputJson = document.getElementById('zones-json-input');
        const btnSaveDb = document.getElementById('btn-save-db');

        function renderAllZones() {
            Object.values(drawnLayers).forEach(layer => map.removeLayer(layer));
            drawnLayers = {};

            zonesData.forEach(zone => {
                if (!zone.coords || zone.coords.length < 3) return;

                let polygon = L.polygon(zone.coords, {
                    color: 'rgba(216, 177, 92, 0.6)', weight: 2, fillColor: '#D8B15C', fillOpacity: 0.08,
                    dashArray: '5 5'
                }).addTo(map);

                polygon.on('mouseover', function () { this.setStyle({ fillOpacity: 0.25, weight: 3, color: '#F9D87E' }); });
                polygon.on('mouseout', function () { this.setStyle({ fillOpacity: 0.08, weight: 2, color: 'rgba(216, 177, 92, 0.6)' }); });

                polygon.bindTooltip(zone.name.split(' - ')[0].toUpperCase(), {
                    permanent: true, direction: 'center', className: 'zone-label-permanent'
                });

                polygon.bindPopup(`
                    <div class="zone-badge">PvP Activo</div>
                    <h4 class="zone-title">${zone.name}</h4>
                    <span style="font-size:10px; color:#aaa">Key: ${zone.key}</span>
                `);
                
                drawnLayers[zone.id] = polygon;
            });
        }

        // Init
        selector.innerHTML = '';
        zonesData.forEach(z => {
            let opt = document.createElement('option');
            opt.value = z.id;
            opt.textContent = z.name;
            selector.appendChild(opt);
        });

        renderAllZones();

        map.getContainer().setAttribute('data-zoom', map.getZoom());
        map.on('zoomend', function() {
            map.getContainer().setAttribute('data-zoom', map.getZoom());
        });

        // ACTIONS
        btnDraw.addEventListener('click', () => {
            currentEditingId = parseInt(selector.value);
            isTracing = true;
            tracePoints = [];
            
            if(drawnLayers[currentEditingId]) {
                map.removeLayer(drawnLayers[currentEditingId]);
            }
            
            actionGroup.classList.add('hidden');
            traceGroup.classList.remove('hidden');
            indicator.classList.remove('hidden');
            map.getContainer().style.cursor = 'crosshair';
            selector.disabled = true;
            btnSaveDb.disabled = true;
        });

        map.on('click', function(e) {
            if(!isTracing) return;
            const y = Math.round(e.latlng.lat);
            const x = Math.round(e.latlng.lng);
            tracePoints.push([y, x]);

            if(tempPolyLine) map.removeLayer(tempPolyLine);
            tempPolyLine = L.polygon(tracePoints, {color: '#facc15', weight: 3, dashArray: '5,5', fillOpacity: 0.3}).addTo(map);
        });

        document.getElementById('btn-finish').addEventListener('click', () => {
            if(tracePoints.length < 3) {
                alert("!Necesitas al menos 3 puntos para hacer un polígono válido!");
                return;
            }
            let zoneIndex = zonesData.findIndex(z => z.id === currentEditingId);
            zonesData[zoneIndex].coords = [...tracePoints];
            
            closeTracing();
            renderAllZones();
        });

        document.getElementById('btn-cancel').addEventListener('click', () => {
            closeTracing();
            renderAllZones();
        });

        function closeTracing() {
            isTracing = false;
            currentEditingId = null;
            if(tempPolyLine) map.removeLayer(tempPolyLine);
            tempPolyLine = null;
            
            actionGroup.classList.remove('hidden');
            traceGroup.classList.add('hidden');
            indicator.classList.add('hidden');
            map.getContainer().style.cursor = 'grab';
            selector.disabled = false;
            btnSaveDb.disabled = false;
        }

        btnSaveDb.addEventListener('click', () => {
            if(confirm('¿Seguro quieres sobreescribir las zonas oficiales en toda la app?')) {
                inputJson.value = JSON.stringify(zonesData);
                saveForm.submit();
            }
        });
    });
</script>
@endpush
