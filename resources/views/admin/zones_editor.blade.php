@extends('layouts.admin')

@section('title', 'Zonas de combate')
@section('page-title', 'Zonas de combate')
@section('page-subtitle', 'Los poligonos del mapa donde puede desarrollarse una partida')

@section('page-actions')
    <button type="button" class="ap-btn ap-btn-sm ap-btn-quiet" onclick="window.location.reload();">Descartar cambios</button>
    <button type="button" id="btn-save-db" class="ap-btn ap-btn-sm ap-btn-primary">
        Publicar zonas
    </button>
@endsection

@push('styles')
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

<div class="ap-flash ap-rise" style="border-color: var(--ap-line-strong)">
    <x-admin.icon name="alert" class="h-4 w-4 shrink-0" style="color: var(--ap-warn)" />
    <span>
        Al publicar se reescribe el mapa para todos los jugadores a la vez. Los cambios que hagas
        aqui no se guardan hasta que pulses <strong>Publicar zonas</strong>.
    </span>
</div>

<form method="POST" action="{{ route('admin.zones.save') }}" id="save-zones-form" class="hidden">
    @csrf
    <input type="hidden" name="zones_json" id="zones-json-input">
</form>

<div class="grid gap-4 lg:grid-cols-[1fr_290px] items-start ap-rise ap-delay-1">
    <div class="ap-card relative" style="height: 680px; overflow: hidden; padding: 6px">
        <div id="admin-map-container" class="w-full h-full" style="background-color: #050608; border-radius: var(--ap-radius-sm);"></div>

        <div id="tracing-indicator" class="hidden ap-tracing-badge">
            Modo dibujo: haz clic en el mapa para marcar cada vertice
        </div>
    </div>

    <aside class="ap-card p-4 self-start">
        <div class="ap-section-head">
            <div>
                <h2 class="ap-section-title">Editar una zona</h2>
                <p class="ap-section-note">Elige la zona y vuelve a trazar su contorno.</p>
            </div>
        </div>

        <div class="ap-field mb-3">
            <label class="ap-label" for="zone-selector">Zona</label>
            <select id="zone-selector" class="ap-select"></select>
        </div>

        <div id="action-buttons" class="flex flex-col gap-2">
            <button id="btn-draw" class="ap-btn ap-btn-block">Redibujar el contorno</button>
            <p class="ap-hint">
                Marca al menos tres puntos sobre el mapa. Cuando confirmes la forma, aun tendras
                que publicar para que sea oficial.
            </p>
        </div>

        <div id="trace-buttons" class="hidden flex-col gap-2">
            <button id="btn-finish" class="ap-btn ap-btn-primary ap-btn-block">Confirmar forma</button>
            <button id="btn-cancel" class="ap-btn ap-btn-block ap-btn-quiet">Cancelar trazado</button>
        </div>
    </aside>
</div>
@endsection

@push('scripts')
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
            traceGroup.classList.add('flex');
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
                alert('Necesitas al menos tres puntos para cerrar una zona.');
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
            traceGroup.classList.remove('flex');
            indicator.classList.add('hidden');
            map.getContainer().style.cursor = 'grab';
            selector.disabled = false;
            btnSaveDb.disabled = false;
        }

        btnSaveDb.addEventListener('click', () => {
            if (confirm('Vas a publicar estas zonas para todos los jugadores. Se sobrescriben las actuales.')) {
                inputJson.value = JSON.stringify(zonesData);
                saveForm.submit();
            }
        });
    });
</script>
@endpush
