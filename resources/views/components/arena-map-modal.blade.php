@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <style>
        #arena-modal-map-container {
            width: 100%;
            height: 400px;
            background-color: #050608;
        }
        .leaflet-popup-content-wrapper { background-color: var(--arena-panel-strong); border: 1px solid var(--arena-line); color: var(--arena-text); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.8); }
        .leaflet-popup-tip { background-color: var(--arena-panel-strong); border: 1px solid var(--arena-line); }
        .zone-title { font-family: 'Cinzel', serif; color: var(--arena-gold); font-size: 16px; font-weight: 700; margin: 0 0 5px 0; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 5px; }
        .zone-badge { display: inline-block; background-color: rgba(216, 177, 92, 0.15); color: var(--arena-gold); border: 1px solid var(--arena-line); padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px; }
        .zone-label-permanent { 
            background: rgba(12, 8, 6, 0.85); border: 1px solid rgba(216, 177, 92, 0.5); border-radius: 4px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.9); color: var(--arena-gold); font-family: 'Cinzel', serif; 
            font-weight: 700; text-align: center; transition: font-size 0.2s, padding 0.2s; letter-spacing: 1px; backdrop-filter: blur(2px);
        }
        .leaflet-tooltip-left.zone-label-permanent::before, .leaflet-tooltip-right.zone-label-permanent::before, .leaflet-tooltip-top.zone-label-permanent::before, .leaflet-tooltip-bottom.zone-label-permanent::before { display: none; }
        
        /* Modal sizing */
        #arena-modal-map-container[data-zoom="-1"] .zone-label-permanent { font-size: 7px; padding: 2px 4px; }
        #arena-map-container[data-zoom="0"] .zone-label-permanent, #arena-modal-map-container[data-zoom="0"] .zone-label-permanent { font-size: 10px; padding: 3px 6px; }
        #arena-map-container[data-zoom="1"] .zone-label-permanent, #arena-modal-map-container[data-zoom="1"] .zone-label-permanent { font-size: 15px; padding: 4px 8px; }
        #arena-map-container[data-zoom="2"] .zone-label-permanent, #arena-modal-map-container[data-zoom="2"] .zone-label-permanent { font-size: 24px; padding: 6px 14px; }
        #arena-map-container[data-zoom="3"] .zone-label-permanent, #arena-modal-map-container[data-zoom="3"] .zone-label-permanent { font-size: 36px; padding: 10px 20px; }

        .leaflet-interactive { transition: fill-opacity 0.2s, stroke-width 0.2s; }
    </style>
@endpush

<div x-data="arenaGlobalMapModal()"
    x-show="isOpen"
    @open-map-modal.window="openModal($event.detail.zone)"
    @keydown.escape.window="closeModal()"
    class="relative z-[100]"
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
    x-cloak>

    <!-- Backdrop -->
    <div x-show="isOpen"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-[#090605]/90 backdrop-blur-[2px] transition-opacity"></div>

    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
            <!-- Modal panel -->
            <div x-show="isOpen"
                @click.away="closeModal()"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                class="relative transform overflow-hidden rounded-xl bg-[var(--arena-panel-strong)] text-left shadow-[var(--arena-shadow)] transition-all sm:my-8 sm:w-full sm:max-w-4xl border border-[var(--arena-line)] ring-1 ring-white/5">
                
                <div class="absolute right-0 top-0 hidden pr-4 pt-4 sm:block z-20">
                    <button type="button" @click="closeModal()" class="rounded-md bg-transparent text-[color:var(--arena-muted)] hover:text-white focus:outline-none focus:ring-2 focus:ring-[color:var(--arena-gold)] focus:ring-offset-2 focus:ring-offset-[#0f0b08]">
                        <span class="sr-only">Cerrar mapa</span>
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="bg-[var(--arena-panel-strong)] bg-[linear-gradient(135deg,rgba(255,255,255,0.02),transparent)]">
                    <div class="px-4 pb-4 pt-5 sm:p-6 sm:pb-4 border-b border-[var(--arena-line)]">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(216,177,92,0.1)] sm:mx-0 sm:h-10 sm:w-10 ring-1 ring-[var(--arena-gold)]">
                                🗺️
                            </div>
                            <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                <h3 class="text-xl font-semibold leading-6 text-[color:var(--arena-gold)] font-[Cinzel]" id="modal-title" x-text="zoneName">
                                    Cargando Ubicación...
                                </h3>
                                <div class="mt-2 text-sm text-[color:var(--arena-muted)]" x-text="zoneDesc">
                                    Analizando topografía táctica...
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Map Container -->
                    <div class="relative w-full overflow-hidden bg-[#050608]">
                        <div id="arena-modal-map-container" class="z-0"></div>
                        <div class="absolute inset-x-0 bottom-0 h-8 bg-gradient-to-t from-[#090706] to-transparent pointer-events-none z-10"></div>
                    </div>
                    
                    <div class="bg-black/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                        <button type="button" @click="closeModal()" class="inline-flex w-full justify-center rounded-md border border-[color:var(--arena-gold)] bg-[rgba(216,177,92,0.1)] px-3 py-2 text-sm font-semibold text-[color:var(--arena-gold)] shadow-sm hover:bg-[color:var(--arena-gold)] hover:text-black sm:ml-3 sm:w-auto transition-colors font-[Cinzel]">
                            Cerrar Mapa
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Only load scripts when needed or inject if already loaded
    document.addEventListener('alpine:init', () => {
        Alpine.data('arenaGlobalMapModal', () => ({
            isOpen: false,
            mapInstance: null,
            targetZoneKey: null,
            zoneName: 'Cargando Ubicación...',
            zoneDesc: 'Analizando topografía táctica...',
            scriptLoaded: false,
            polygons: {},

            async openModal(zoneKey) {
                this.targetZoneKey = zoneKey;
                this.isOpen = true;
                
                await this.loadDependencies();
                
                // Allow modal to render
                setTimeout(() => {
                    this.initOrUpdateMap();
                }, 100);
            },
            
            closeModal() {
                this.isOpen = false;
            },

            async loadDependencies() {
                if (this.scriptLoaded) return Promise.resolve();
                
                // If leaflet isn't present in window, we inject it manually
                // Since we pushed it to the layout in another view, it might not be here.
                return new Promise((resolve) => {
                    let leafletLoaded = typeof L !== 'undefined';
                    let zonesLoaded = typeof PREDEFINED_ZONES_JSON !== 'undefined';
                    
                    let checkInterval = setInterval(() => {
                        if (typeof L !== 'undefined' && typeof PREDEFINED_ZONES_JSON !== 'undefined') {
                            clearInterval(checkInterval);
                            this.scriptLoaded = true;
                            resolve();
                        }
                    }, 100);

                    // Dynamically inject if not found
                    if (!leafletLoaded && !document.getElementById('leaflet-js-cdn')) {
                        let script = document.createElement('script');
                        script.id = 'leaflet-js-cdn';
                        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        document.head.appendChild(script);
                    }
                    if (!zonesLoaded && !document.getElementById('arena-zones-js')) {
                        let zscript = document.createElement('script');
                        zscript.id = 'arena-zones-js';
                        zscript.src = '{{ asset("js/arena-zones.js") }}';
                        document.head.appendChild(zscript);
                    }
                });
            },

            initOrUpdateMap() {
                const w = 1086, h = 1086;
                const mapEl = document.getElementById('arena-modal-map-container');
                
                if (!this.mapInstance) {
                    this.mapInstance = L.map('arena-modal-map-container', { crs: L.CRS.Simple, minZoom: -1, maxZoom: 3, zoomControl: false });
                    const bounds = [[0, 0], [h, w]];
                    L.imageOverlay('{{ asset("mapa/mapa-regnum-limpio.jpg") }}', bounds).addTo(this.mapInstance);
                    this.mapInstance.fitBounds(bounds);
                    L.control.zoom({ position: 'bottomright' }).addTo(this.mapInstance);

                    // Draw all zones
                    PREDEFINED_ZONES_JSON.forEach(zone => {
                        if (!zone.coords || zone.coords.length < 3) return;

                        let polygon = L.polygon(zone.coords, {
                            color: 'rgba(216, 177, 92, 0.4)', 
                            weight: 2, fillColor: '#D8B15C', fillOpacity: 0.04, dashArray: '5 5'
                        }).addTo(this.mapInstance);

                        let shortName = zone.name.split(' - ')[0].toUpperCase();
                        polygon.bindTooltip(shortName, { permanent: true, direction: 'center', className: 'zone-label-permanent' });
                        
                        this.polygons[zone.key] = { layer: polygon, data: zone };
                    });

                    // Dynamic text scale
                    mapEl.setAttribute('data-zoom', this.mapInstance.getZoom());
                    this.mapInstance.on('zoomend', () => mapEl.setAttribute('data-zoom', this.mapInstance.getZoom()));
                }

                // Invalidate size because modal was hidden
                this.mapInstance.invalidateSize();

                // Focus on the specific zone
                if (this.targetZoneKey && this.polygons[this.targetZoneKey]) {
                    const activeZone = this.polygons[this.targetZoneKey];
                    this.zoneName = activeZone.data.name;
                    
                    // Reset all styles
                    Object.values(this.polygons).forEach(p => {
                        p.layer.setStyle({ color: 'rgba(216, 177, 92, 0.4)', fillOpacity: 0.04, weight: 1 });
                    });

                    // Highlight target
                    activeZone.layer.setStyle({ color: '#ef4444', fillOpacity: 0.35, weight: 3 });
                    
                    // Fly to bounds smoothly
                    this.mapInstance.flyToBounds(activeZone.layer.getBounds(), { padding: [20, 20], maxZoom: 1, duration: 1.5 });
                }
            }
        }));
    });
</script>
@endpush
