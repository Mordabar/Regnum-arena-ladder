@props(['mode' => null])
{{-- 2v2 / 3v3 comparten ranking, pero el moderador necesita ver de un vistazo
     cual esta mirando: los tamanos de equipo cambian toda la lectura. --}}
<span {{ $attributes->merge(['class' => 'ap-badge ap-badge-neutral']) }}>{{ $mode ?: '2v2' }}</span>
