@props(['date' => null, 'empty' => '—'])
@php
    // Relativo para leer de un vistazo, absoluto en el title para cuando hay
    // que reconstruir lo que paso. Y en espanol, como el resto del panel.
    $moment = $date ? \Illuminate\Support\Carbon::parse($date) : null;
@endphp
{{--
    Sin saltos de linea alrededor del @if: Blade los emite tal cual, y como este
    componente se usa en mitad de una frase quedaba un espacio suelto antes del
    punto -"se resuelve solo en 9 minutos ."- en cada sitio donde aparece.
--}}
@if($moment)<time {{ $attributes }} datetime="{{ $moment->toIso8601String() }}" title="{{ $moment->format('d/m/Y H:i') }}">{{ $moment->locale('es')->diffForHumans() }}</time>@else<span {{ $attributes }}>{{ $empty }}</span>@endif
