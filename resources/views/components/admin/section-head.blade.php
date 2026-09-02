@props(['title', 'note' => null, 'icon' => 'dot', 'tone' => 'default'])

{{-- Cabecera de seccion con su marca.

     Sin un icono al lado, ocho tarjetas grises seguidas se leen como una sola
     pared y hay que releer el titulo cada vez para saber donde estas. La marca
     da un ancla que se reconoce de reojo. --}}
<div class="ap-section-head">
    <div class="ap-section-lead">
        <span class="ap-section-mark ap-section-mark-{{ $tone }}">
            <x-admin.icon :name="$icon" class="h-4 w-4" />
        </span>
        <div class="min-w-0">
            <h2 class="ap-section-title">{{ $title }}</h2>
            @if($note)
                <p class="ap-section-note">{{ $note }}</p>
            @endif
            {{ $slot }}
        </div>
    </div>
    @isset($aside)
        <div class="shrink-0">{{ $aside }}</div>
    @endisset
</div>
