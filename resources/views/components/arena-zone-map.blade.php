@props([
    'zoneKey' => null,
    'height' => '400px',
    'interactive' => true,
    'id' => 'arenaZoneMap-' . uniqid(),
])

{{-- Mapa de zonas.

     Sin script propio: el arranque vive en el layout y se pasa sobre cualquier
     mapa que aparezca, tambien sobre los que llegan con el panel repintado.
     Antes el mapa del cruce se pintaba solo si ya habia enfrentamiento al
     cargar la pagina, y como el cruce llega por el sondeo, el boton de la zona
     no abria nada. --}}
<div class="arena-map-container" style="height: {{ $height }}">
    <div id="{{ $id }}"
         data-arena-map
         data-arena-map-zone="{{ $zoneKey }}"
         data-arena-map-interactive="{{ $interactive ? '1' : '0' }}"
         style="height: 100%; width: 100%; background-color: #050608;">
        {{-- Lo que se ve mientras el mapa llega, y lo que queda si no llega:
             el nombre de la zona sigue siendo la informacion que hacia falta,
             asi que un fallo de red deja un aviso y no un hueco negro. --}}
        <div class="arena-map-fallback" data-arena-map-fallback>
            <span data-arena-map-fallback-text>Cargando el mapa…</span>
            @if($zoneKey)
                <b>{{ \App\Models\ArenaMatch::zoneLabel($zoneKey) ?? $zoneKey }}</b>
            @endif
        </div>
    </div>
</div>
