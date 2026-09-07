@props(['date' => null, 'empty' => '—'])
@php
    // Relativo para leer de un vistazo, absoluto en el title para cuando hay
    // que reconstruir lo que paso. Y en espanol, como el resto del panel.
    $moment = $date ? \Illuminate\Support\Carbon::parse($date) : null;
@endphp
@if($moment)
    <time {{ $attributes }} datetime="{{ $moment->toIso8601String() }}" title="{{ $moment->format('d/m/Y H:i') }}">{{ $moment->locale('es')->diffForHumans() }}</time>
@else
    <span {{ $attributes }}>{{ $empty }}</span>
@endif
