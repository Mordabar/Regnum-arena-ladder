@props([
    'items' => [],
])

@if(count($items) > 0)
<nav {{ $attributes->class(['flex items-center gap-2 text-sm']) }} aria-label="Breadcrumb">
    <a href="{{ route('home') }}" class="text-[color:var(--arena-muted)] hover:text-[color:var(--arena-gold-soft)] transition-colors">
        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
    </a>
    @foreach($items as $item)
        <svg class="w-3.5 h-3.5 text-[color:var(--arena-muted)] opacity-50 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
        @if(isset($item['url']))
            <a href="{{ $item['url'] }}" class="text-[color:var(--arena-muted)] hover:text-[color:var(--arena-gold-soft)] transition-colors whitespace-nowrap">{{ $item['label'] }}</a>
        @else
            <span class="text-[color:var(--arena-sand)] font-medium whitespace-nowrap">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
@endif
