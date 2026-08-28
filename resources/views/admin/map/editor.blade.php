@extends('layouts.arena')

@section('title', 'Editor de Mapas - Admin')

@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <style>
        #arena-map-container {
            width: 100%;
            height: calc(100vh - 16rem);
            min-height: 600px;
            background-color: #050608;
            border: 1px solid var(--arena-line);
            border-radius: 0.5rem;
            box-shadow: var(--arena-shadow);
        }

        .leaflet-popup-content-wrapper { background-color: var(--arena-panel-strong); border: 1px solid var(--arena-line); color: var(--arena-text); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.8); }
        .leaflet-popup-tip { background-color: var(--arena-panel-strong); border: 1px solid var(--arena-line); }
        
        .zone-title { font-family: 'Cinzel', serif; color: var(--arena-gold); font-size: 16px; font-weight: 700; margin: 0 0 5px 0; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 5px; }

        .zone-label-permanent { 
            background: rgba(12, 8, 6, 0.85); border: 1px solid rgba(216, 177, 92, 0.5); border-radius: 4px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.9); color: var(--arena-gold); font-family: 'Cinzel', serif; 
            font-weight: 700; text-align: center; transition: font-size 0.2s, padding 0.2s; letter-spacing: 1px; backdrop-filter: blur(2px);
        }
        .leaflet-tooltip-left.zone-label-permanent::before, .leaflet-tooltip-right.zone-label-permanent::before, .leaflet-tooltip-top.zone-label-permanent::before, .leaflet-tooltip-bottom.zone-label-permanent::before { display: none; }

        #arena-map-container[data-zoom="-1"] .zone-label-permanent { font-size: 7px; padding: 2px 4px; }
        #arena-map-container[data-zoom="0"] .zone-label-permanent { font-size: 10px; padding: 3px 6px; }
        #arena-map-container[data-zoom="1"] .zone-label-permanent { font-size: 15px; padding: 4px 8px; }
        #arena-map-container[data-zoom="2"] .zone-label-permanent { font-size: 24px; padding: 6px 14px; }
        #arena-map-container[data-zoom="3"] .zone-label-permanent { font-size: 36px; padding: 10px 20px; }

        /* Panel Editor Flotante */
        #editor-panel {
            position: absolute; top: 20px; right: 20px; z-index: 1000;
            background: var(--arena-panel-strong); border: 1px solid var(--arena-gold);
            padding: 20px; border-radius: 8px; color: white; width: 320px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.8); backdrop-filter: blur(10px);
        }
        .form-group label { display: block; font-size: 12px; color: var(--arena-muted); margin-bottom: 5px; }
        select { width: 100%; background: #000; color: white; border: 1px solid var(--arena-line); padding: 8px; border-radius: 4px; outline: none; }
        
        #json-output { width: 100%; height: 100px; background: #000; color: #a3a3a3; border: 1px solid var(--arena-line); margin-top: 15px; font-family: monospace; font-size: 11px; padding: 8px; resize: none; box-sizing: border-box; }
        .btn-gold { width:100%; padding:0.5rem; text-align:center; font-family:'Cinzel'; color:var(--arena-gold); border:1px solid var(--arena-gold); border-radius:0.375rem; background:rgba(216,177,92,0.1); margin-bottom:0.75rem; transition: background 0.2s;}
        .btn-gold:hover { background:var(--arena-gold); color:black;}
        .btn-red { width:100%; padding:0.5rem; text-align:center; font-family:'Cinzel'; color:#ef4444; border:1px solid #ef4444; border-radius:0.375rem; background:rgba(239,68,68,0.1); margin-bottom:0.75rem;}
        .btn-red:hover { background:#ef4444; color:white; }
        .btn-green { width:100%; padding:0.5rem; text-align:center; font-family:'Cinzel'; color:#22c55e; border:1px solid #22c55e; border-radius:0.375rem; background:rgba(34,197,94,0.1); margin-bottom:0.75rem;}
        .btn-green:hover { background:#22c55e; color:white; }

        #tracing-indicator { display: none; background: #ef4444; color: white; text-align: center; font-size: 12px; font-weight: bold; padding: 8px; border-radius: 4px; margin-bottom: 10px; animation: pulse 1s infinite alternate; }
        @keyframes pulse { from { opacity: 0.7; } to { opacity: 1; } }
        .leaflet-interactive { transition: fill-opacity 0.2s, stroke-width 0.2s; }
    </style>
@endpush

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 w-full">
    <x-arena-breadcrumbs :items="[['label' => 'Admin', 'url' => route('admin.dashboard')], ['label' => 'Map Editor']]" class="mb-6" />

    <div class="mb-6">
        <h1 class="text-3xl font-bold font-[Cinzel] text-white flex items-center gap-3">
            <span class="text-3xl">⚒️</span> Herramienta Cartográfica
        </h1>
        <p class="mt-2 text-sm text-[color:var(--arena-muted)] max-w-3xl">
            Ajusta los vértices y ubicaciones de las Zonas de Cacería seleccionándolas en el dropdown y redibujando los polígonos. Exporta el archivo JSON final para actualizar la base de la aplicación.
        </p>
    </div>

    <div class="relative w-full rounded-xl overflow-hidden border border-[color:var(--arena-line)] ring-1 ring-white/5">
        <div id="arena-map-container" class="z-0"></div>

        <div id="editor-panel">
            <div id="tracing-indicator">🎯 MODO DIBUJO ACTIVO <br><span class="text-[10px] font-normal">Da clic en el mapa para anclar los vértices del área.</span></div>

            <div class="form-group mb-4">
                <label>Selecciona una zona a editar:</label>
                <select id="zone-selector"></select>
            </div>

            <div id="action-buttons">
                <button type="button" id="btn-draw" class="btn-red">✏️ Redibujar Zona Seleccionada</button>
                <button type="button" id="btn-export" class="btn-gold">📋 Copiar a Portapapeles</button>
            </div>

            <div id="trace-buttons" style="display: none;">
                <button type="button" id="btn-finish" class="btn-green">✅ Terminar y Guardar Polígono</button>
                <button type="button" id="btn-cancel" class="btn-gold" style="border-color:var(--arena-muted); color:var(--arena-muted);">Cancelar Edición</button>
            </div>

            <textarea id="json-output" readonly placeholder="El JSON final saldrá aquí..." onclick="this.select()"></textarea>
            <p class="text-[10px] text-[color:var(--arena-muted)] mt-2 italic">Copia este JSON y reemplázalo en `public/js/arena-zones.js` para aplicar los cambios a nivel sistema.</p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="{{ asset('js/arena-zones.js') }}"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof L === 'undefined' || typeof PREDEFINED_ZONES_JSON === 'undefined') return;

        // Trabajamos con una copia profunda para no mutar el window global hasta que exportemos
        let zonesData = JSON.parse(JSON.stringify(PREDEFINED_ZONES_JSON));

        const w = 1086, h = 1086;
        const mapContainer = document.getElementById('arena-map-container');
        const map = L.map('arena-map-container', { crs: L.CRS.Simple, minZoom: -1, maxZoom: 3, zoomControl: false });
        
        const bounds = [[0, 0], [h, w]];
        L.imageOverlay('{{ asset("mapa/mapa-regnum-limpio.jpg") }}', bounds).addTo(map);
        map.fitBounds(bounds);
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        let drawnLayers = {};
        let isTracing = false;
        let currentEditingId = null;
        let tracePoints = [];
        let tempPolyLine = null;

        const selector = document.getElementById('zone-selector');
        const btnDraw = document.getElementById('btn-draw');
        const btnExport = document.getElementById('btn-export');
        const actionGroup = document.getElementById('action-buttons');
        const traceGroup = document.getElementById('trace-buttons');
        const indicator = document.getElementById('tracing-indicator');
        const output = document.getElementById('json-output');

        function initMap() {
            selector.innerHTML = '';
            zonesData.forEach(z => {
                let opt = document.createElement('option');
                opt.value = z.id;
                opt.textContent = z.name;
                selector.appendChild(opt);
            });

            renderAllZones();
            updateJSON();
        }

        function renderAllZones() {
            Object.values(drawnLayers).forEach(layer => map.removeLayer(layer));
            drawnLayers = {};

            zonesData.forEach(zone => {
                if (!zone.coords || zone.coords.length < 3) return;

                let polygon = L.polygon(zone.coords, {
                    color: 'rgba(216, 177, 92, 0.6)', weight: 2, fillColor: '#D8B15C', fillOpacity: 0.08, dashArray: '5 5'
                }).addTo(map);

                polygon.on('mouseover', function () { this.setStyle({ fillOpacity: 0.25, weight: 3, color: '#F9D87E' }); });
                polygon.on('mouseout', function () { this.setStyle({ fillOpacity: 0.08, weight: 2, color: 'rgba(216, 177, 92, 0.6)' }); });

                let shortName = zone.name.split(' - ')[0].toUpperCase();
                polygon.bindTooltip(shortName, { permanent: true, direction: 'center', className: 'zone-label-permanent' });

                drawnLayers[zone.id] = polygon;
            });
        }

        btnDraw.addEventListener('click', () => {
            currentEditingId = parseInt(selector.value);
            isTracing = true;
            tracePoints = [];
            
            if(drawnLayers[currentEditingId]) {
                map.removeLayer(drawnLayers[currentEditingId]);
            }
            
            actionGroup.style.display = 'none';
            traceGroup.style.display = 'block';
            indicator.style.display = 'block';
            map.getContainer().style.cursor = 'crosshair';
            selector.disabled = true;
        });

        map.on('click', function(e) {
            if(!isTracing) return;
            const y = Math.round(e.latlng.lat);
            const x = Math.round(e.latlng.lng);
            tracePoints.push([y, x]);

            if(tempPolyLine) map.removeLayer(tempPolyLine);
            tempPolyLine = L.polygon(tracePoints, {color: '#facc15', weight: 3, dashArray: '5,5', fillOpacity: 0.5}).addTo(map);
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
            updateJSON();
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
            
            actionGroup.style.display = 'block';
            traceGroup.style.display = 'none';
            indicator.style.display = 'none';
            map.getContainer().style.cursor = 'grab';
            selector.disabled = false;
        }

        function updateJSON() {
            output.value = JSON.stringify(zonesData, null, 2);
        }

        btnExport.addEventListener('click', () => {
            output.select();
            document.execCommand('copy');
            let original = btnExport.innerText;
            btnExport.innerText = "¡Copiado!";
            setTimeout(() => btnExport.innerText = original, 2000);
        });

        mapContainer.setAttribute('data-zoom', map.getZoom());
        map.on('zoomend', function() { mapContainer.setAttribute('data-zoom', map.getZoom()); });

        initMap();
    });
</script>
@endpush
