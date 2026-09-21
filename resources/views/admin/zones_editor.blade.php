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

    /* El numero del puesto, pegado al punto que se esta editando. */
    .zone-meet-tag {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 2px solid #0c0806;
        color: #0c0806;
        font-family: 'Inter', sans-serif;
        font-size: 11px;
        font-weight: 800;
        line-height: 1;
        box-shadow: 0 2px 8px rgba(0,0,0,.7);
    }
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

        <div id="meeting-indicator" class="hidden ap-tracing-badge" style="background: #f4a261; color: #1a1209">
            Haz clic donde quieres que queden los dos equipos
        </div>
    </div>

    <aside class="ap-card p-4 self-start">
        <x-admin.section-head title="Editar una zona" icon="map"
                              note="Elige la zona y vuelve a trazar su contorno." />

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

            <hr class="my-1" style="border: 0; border-top: 1px solid var(--ap-line)">

            <div class="ap-label" style="display:flex; align-items:center; gap:6px">
                <span style="width:9px; height:9px; border-radius:50%; background:#ff4d4f; display:inline-block"></span>
                Punto 1
            </div>
            <p class="ap-hint" id="meeting-state">—</p>
            <button id="btn-meeting" class="ap-btn ap-btn-block">Mover el punto 1</button>
            <button id="btn-meeting-auto" class="ap-btn ap-btn-block ap-btn-quiet">Volver al automatico</button>

            <div class="ap-label mt-2" style="display:flex; align-items:center; gap:6px">
                <span style="width:9px; height:9px; border-radius:50%; background:#5eb0ff; display:inline-block"></span>
                Punto 2 <span style="opacity:.6; font-weight:400">(opcional)</span>
            </div>
            <p class="ap-hint" id="meeting-b-state">—</p>
            <button id="btn-meeting-b" class="ap-btn ap-btn-block">Colocar el punto 2</button>
            <button id="btn-meeting-b-clear" class="ap-btn ap-btn-block ap-btn-quiet">Quitar el punto 2</button>

            <p class="ap-hint">
                Es el sitio que el cruce le indica a los dos equipos para quedar. El primero sale
                solo si no lo tocas, en la parte mas interior de la zona. Si pones el segundo, cada
                enfrentamiento sale en uno de los dos al azar: la misma zona deja de jugarse siempre
                en el mismo claro.
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
{{-- Las zonas llegan del servidor, no de un fichero cacheable: el editor tiene
     que estar viendo exactamente lo mismo que esta publicado. --}}
<script>window.ARENA_ZONES_CONFIG = @json($zonas);</script>
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

        let meetingLayers = [];
        // 0 = no se esta colocando nada; 1 o 2 = el puesto que se esta moviendo.
        let colocandoPuesto = 0;

        const selector = document.getElementById('zone-selector');
        const btnDraw = document.getElementById('btn-draw');
        const btnMeeting = document.getElementById('btn-meeting');
        const btnMeetingAuto = document.getElementById('btn-meeting-auto');
        const btnMeetingB = document.getElementById('btn-meeting-b');
        const btnMeetingBClear = document.getElementById('btn-meeting-b-clear');
        const meetingState = document.getElementById('meeting-state');
        const meetingBState = document.getElementById('meeting-b-state');
        const meetingIndicator = document.getElementById('meeting-indicator');

        // Rojo el primero, azul el segundo, y los mismos colores en el mapa y
        // en las etiquetas del panel: con dos puntos identicos no habria forma
        // de saber cual se esta moviendo.
        const COLOR_PUESTO = { 1: '#ff4d4f', 2: '#5eb0ff' };
        const actionGroup = document.getElementById('action-buttons');
        const traceGroup = document.getElementById('trace-buttons');
        const indicator = document.getElementById('tracing-indicator');
        const saveForm = document.getElementById('save-zones-form');
        const inputJson = document.getElementById('zones-json-input');
        const btnSaveDb = document.getElementById('btn-save-db');

        function zonaSeleccionada() {
            return zonesData.find(z => z.id === parseInt(selector.value)) || null;
        }

        /* Los puntos de encuentro.

           El calculo no vive aqui: lo sirve window.ArenaMapPoints, el mismo que
           usa el mapa del jugador. Copiarlo acabaria dando dos puntos distintos
           para la misma zona, uno el que coloca el admin y otro el que ve quien
           tiene que ir a pelear.

           Dorado es el punto que sale solo; naranja, uno movido a mano. */
        function renderMeetingPoints() {
            meetingLayers.forEach(layer => map.removeLayer(layer));
            meetingLayers = [];

            if (!window.ArenaMapPoints) {
                meetingState.textContent = 'No se pudo cargar el calculo del punto.';
                meetingBState.textContent = '—';
                return;
            }

            const actual = zonaSeleccionada();
            const suyos = actual ? window.ArenaMapPoints.todosDeZona(actual) : [];
            const primero = suyos.find(p => p.slot === 1) || null;
            const segundo = suyos.find(p => p.slot === 2) || null;

            // El estado se decide aqui y no dentro del bucle: una zona sin
            // contorno no entra en el bucle, y el panel se quedaba enseñando
            // el estado de la zona anterior.
            if (!actual) {
                meetingState.textContent = 'Elige una zona.';
            } else if (!primero) {
                meetingState.textContent = 'Sin contorno todavia: dibujalo y vuelve.';
            } else if (primero.fijado) {
                meetingState.textContent = 'Fijado a mano en ' + primero.punto[0] + ', ' + primero.punto[1] + '.';
            } else {
                meetingState.textContent = 'Automatico, en la parte mas interior de la zona.';
            }

            if (!actual) {
                meetingBState.textContent = '—';
            } else if (segundo) {
                meetingBState.textContent = 'Puesto en ' + segundo.punto[0] + ', ' + segundo.punto[1] +
                    '. Los cruces salen en uno de los dos.';
            } else {
                meetingBState.textContent = 'Sin poner. Todos los cruces de esta zona van al punto 1.';
            }

            btnMeetingBClear.disabled = !segundo;

            zonesData.forEach(zone => {
                const esActual = actual && zone.id === actual.id;

                window.ArenaMapPoints.todosDeZona(zone).forEach(sitio => {
                    if (!sitio) { return; }

                    const color = COLOR_PUESTO[sitio.slot] || COLOR_PUESTO[1];

                    meetingLayers.push(L.circle(sitio.punto, {
                        radius: Math.max(18, Math.min(sitio.holgura * 0.55, 55)),
                        color: color, weight: esActual ? 2 : 1, dashArray: '4 5',
                        fillColor: color, fillOpacity: esActual ? 0.22 : 0.06,
                        interactive: false,
                    }).addTo(map));

                    // Aro oscuro detras: el punto tiene que leerse igual sobre
                    // nieve que sobre bosque.
                    meetingLayers.push(L.circleMarker(sitio.punto, {
                        radius: esActual ? 10 : 6,
                        color: '#0c0806', weight: 3, opacity: 0.85,
                        fillColor: color, fillOpacity: 1,
                        interactive: false,
                    }).addTo(map));

                    if (esActual) {
                        meetingLayers.push(L.circleMarker(sitio.punto, {
                            radius: 3, weight: 0,
                            fillColor: '#fff', fillOpacity: 0.92,
                            interactive: false,
                        }).addTo(map));

                        // El numero solo en la zona que se edita: con catorce
                        // zonas y dos puntos cada una, el mapa entero lleno de
                        // numeros no se lee.
                        meetingLayers.push(L.marker(sitio.punto, {
                            interactive: false,
                            icon: L.divIcon({
                                className: '',
                                html: '<span class="zone-meet-tag" style="background:' + color + '">' + sitio.slot + '</span>',
                                iconSize: [18, 18],
                                iconAnchor: [-6, 20],
                            }),
                        }).addTo(map));
                    }
                });
            });
        }

        function renderAllZones() {
            Object.values(drawnLayers).forEach(layer => map.removeLayer(layer));
            drawnLayers = {};

            zonesData.forEach(zone => {
                if (!zone.coords || zone.coords.length < 3) return;

                // La zona que se esta editando va marcada: con catorce
                // contornos iguales no se sabia cual se estaba tocando.
                const esActual = zone.id === parseInt(selector.value);

                let polygon = L.polygon(zone.coords, {
                    color: esActual ? '#ff5a4d' : 'rgba(216, 177, 92, 0.6)',
                    weight: esActual ? 3 : 2,
                    fillColor: esActual ? '#ff4d4f' : '#D8B15C',
                    fillOpacity: esActual ? 0.18 : 0.06,
                    dashArray: esActual ? null : '5 5'
                }).addTo(map);

                polygon.on('mouseover', function () { this.setStyle({ fillOpacity: esActual ? 0.26 : 0.2, weight: 3 }); });
                polygon.on('mouseout', function () { this.setStyle({ fillOpacity: esActual ? 0.18 : 0.06, weight: esActual ? 3 : 2 }); });

                polygon.bindTooltip(zone.name.split(' - ')[0].toUpperCase(), {
                    permanent: true, direction: 'center', className: 'zone-label-permanent'
                });

                polygon.bindPopup(`
                    <div class="zone-badge">PvP Activo</div>
                    <h4 class="zone-title">${zone.name}</h4>
                    <span style="font-size:10px; color:#aaa">Key: ${zone.key}</span>
                `);

                /* Un clic sobre el poligono no llega al mapa.

                   Leaflet reparte el clic entre el poligono y el mapa, en ese
                   orden, pero para y no sigue si alguien lo ha parado. Y abrir
                   el globo de informacion lo para: es lo primero que hace.
                   Resultado, el `map.on('click')` de abajo solo se enteraba de
                   los clics en el mar, y colocar el punto de encuentro DENTRO
                   de su zona -que es lo unico que tiene sentido- no hacia
                   nada. Por eso se escucha tambien aqui. */
                polygon.on('click', function (e) {
                    if (!colocandoPuesto && !isTracing) { return; }

                    this.closePopup();
                    atenderClicDelMapa(e.latlng);
                });

                drawnLayers[zone.id] = polygon;
            });

            renderMeetingPoints();
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

        function empezarAColocar(puesto) {
            const zona = zonaSeleccionada();
            if (!zona || !zona.coords || zona.coords.length < 3) {
                alert('Esa zona todavia no tiene contorno. Dibujalo primero.');
                return;
            }

            colocandoPuesto = puesto;
            actionGroup.classList.add('hidden');
            meetingIndicator.textContent = 'Haz clic donde quieres que queden los dos equipos (punto ' + puesto + ')';
            meetingIndicator.style.background = COLOR_PUESTO[puesto];
            meetingIndicator.style.color = puesto === 2 ? '#04121f' : '#1a1209';
            meetingIndicator.classList.remove('hidden');
            map.getContainer().style.cursor = 'crosshair';
            selector.disabled = true;
            btnSaveDb.disabled = true;
        }

        btnMeeting.addEventListener('click', () => empezarAColocar(1));
        btnMeetingB.addEventListener('click', () => empezarAColocar(2));

        btnMeetingAuto.addEventListener('click', () => {
            const zona = zonaSeleccionada();
            if (!zona) return;

            delete zona.meeting;
            renderMeetingPoints();
        });

        btnMeetingBClear.addEventListener('click', () => {
            const zona = zonaSeleccionada();
            if (!zona) return;

            delete zona.meeting_b;
            renderMeetingPoints();
        });

        // Cambiar de zona mueve el foco y actualiza el estado del panel.
        selector.addEventListener('change', renderAllZones);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && colocandoPuesto) { cerrarPuntoDeEncuentro(); }
        });

        function cerrarPuntoDeEncuentro() {
            colocandoPuesto = 0;
            actionGroup.classList.remove('hidden');
            meetingIndicator.classList.add('hidden');
            meetingIndicator.style.background = '';
            meetingIndicator.style.color = '';
            map.getContainer().style.cursor = 'grab';
            selector.disabled = false;
            btnSaveDb.disabled = false;
        }

        map.on('click', function (e) { atenderClicDelMapa(e.latlng); });

        function atenderClicDelMapa(latlng) {
            if (colocandoPuesto) {
                const zona = zonaSeleccionada();
                const punto = [Math.round(latlng.lat), Math.round(latlng.lng)];

                // Sin el calculo cargado no se puede comprobar nada, pero
                // tampoco se puede dejar al admin encerrado en modo colocar con
                // el boton de publicar bloqueado: se sale y se dice por que.
                if (!window.ArenaMapPoints) {
                    cerrarPuntoDeEncuentro();
                    alert('No se pudo cargar el calculo del punto de encuentro. Recarga la pagina.');
                    return;
                }

                // Un punto fuera de su zona manda a los equipos a otro sitio:
                // se avisa antes de guardarlo, no despues.
                if (window.ArenaMapPoints.distanciaAlBorde(punto, zona.coords) <= 0 &&
                    !confirm('Ese punto cae FUERA de ' + zona.name + '. Lo guardas igual?')) {
                    return;
                }

                if (colocandoPuesto === 2) {
                    zona.meeting_b = punto;
                } else {
                    zona.meeting = punto;
                }

                cerrarPuntoDeEncuentro();
                renderMeetingPoints();
                return;
            }

            if(!isTracing) return;
            tracePoints.push([Math.round(latlng.lat), Math.round(latlng.lng)]);

            if(tempPolyLine) map.removeLayer(tempPolyLine);
            tempPolyLine = L.polygon(tracePoints, {color: '#facc15', weight: 3, dashArray: '5,5', fillOpacity: 0.3}).addTo(map);
        }

        document.getElementById('btn-finish').addEventListener('click', () => {
            if(tracePoints.length < 3) {
                alert('Necesitas al menos tres puntos para cerrar una zona.');
                return;
            }
            let zoneIndex = zonesData.findIndex(z => z.id === currentEditingId);
            const zona = zonesData[zoneIndex];
            zona.coords = [...tracePoints];

            // Redibujar el contorno puede dejar el punto de encuentro fuera de
            // su propia zona. Callarselo mandaria a los equipos a un sitio que
            // ya no es la zona, asi que vuelve al automatico y se avisa.
            if (window.ArenaMapPoints) {
                const expulsados = [];

                [['meeting', 1], ['meeting_b', 2]].forEach(([campo, puesto]) => {
                    if (window.ArenaMapPoints.esUnPunto(zona[campo]) &&
                        window.ArenaMapPoints.distanciaAlBorde(zona[campo], zona.coords) <= 0) {
                        delete zona[campo];
                        expulsados.push(puesto);
                    }
                });

                if (expulsados.length) {
                    alert('El nuevo contorno deja fuera ' +
                          (expulsados.length === 2 ? 'los dos puntos de encuentro' : 'el punto ' + expulsados[0]) +
                          ' de ' + zona.name + '. Se han quitado; vuelve a colocarlos donde quieras.');
                }
            }

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
