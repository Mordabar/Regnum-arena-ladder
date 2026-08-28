@props([
    'steps' => [],
    'current' => 0,
])

@php
    $totalSteps = count($steps);
@endphp

@if($totalSteps > 0)
<nav {{ $attributes->class(['flex items-center gap-0']) }} aria-label="Progreso">
    @foreach($steps as $index => $step)
        @php
            $stepNumber = $index + 1;
            $isCompleted = $stepNumber < $current;
            $isActive = $stepNumber === $current;
            $isPending = $stepNumber > $current;

            $circleClass = $isCompleted
                ? 'bg-emerald-500/90 border-emerald-400/40 text-white shadow-[0_0_12px_rgba(16,185,129,0.3)]'
                : ($isActive
                    ? 'bg-[linear-gradient(180deg,#f3d888,#c99534)] border-amber-400/40 text-[#28190a] shadow-[0_0_16px_rgba(216,177,92,0.35)]'
                    : 'bg-[rgba(15,10,8,0.8)] border-[color:var(--arena-line)] text-[color:var(--arena-muted)]');

            $labelClass = $isActive
                ? 'text-[color:var(--arena-gold-soft)] font-semibold'
                : ($isCompleted ? 'text-emerald-300' : 'text-[color:var(--arena-muted)]');

            $lineClass = $isCompleted
                ? 'bg-emerald-500/60'
                : 'bg-[color:var(--arena-line)]';
        @endphp

        <div class="flex items-center {{ $index < $totalSteps - 1 ? 'flex-1' : '' }}">
            <div class="flex flex-col items-center gap-1.5">
                <div class="flex h-8 w-8 items-center justify-center rounded-full border text-xs font-bold transition-all duration-300 {{ $circleClass }}">
                    @if($isCompleted)
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    @else
                        {{ $stepNumber }}
                    @endif
                </div>
                <span class="hidden whitespace-nowrap text-[0.65rem] tracking-wider uppercase sm:block {{ $labelClass }}">
                    {{ $step }}
                </span>
            </div>

            @if($index < $totalSteps - 1)
                <div class="mx-2 h-px flex-1 {{ $lineClass }} transition-colors duration-300"></div>
            @endif
        </div>
    @endforeach
</nav>
@endif
