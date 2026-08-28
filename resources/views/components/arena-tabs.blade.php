@props([
    'tabs' => [],
    'activeTab' => '',
    'id' => 'arenaTabs',
])

@php
    if (!$activeTab && count($tabs) > 0) {
        $activeTab = array_key_first($tabs);
    }
@endphp

<div {{ $attributes }} id="{{ $id }}">
    {{-- Tab bar --}}
    <div class="flex rounded-2xl border border-[color:var(--arena-line)] bg-[rgba(12,8,6,0.7)] p-1" role="tablist">
        @foreach($tabs as $key => $label)
            <button
                type="button"
                role="tab"
                aria-selected="{{ $key === $activeTab ? 'true' : 'false' }}"
                aria-controls="{{ $id }}-panel-{{ $key }}"
                data-arena-tab="{{ $key }}"
                data-arena-tab-group="{{ $id }}"
                class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold transition-all duration-200
                    {{ $key === $activeTab
                        ? 'bg-[linear-gradient(180deg,rgba(63,45,31,0.85),rgba(22,15,11,0.95))] text-[color:var(--arena-gold-soft)] shadow-[0_4px_16px_rgba(0,0,0,0.2),inset_0_1px_0_rgba(255,215,134,0.12)]'
                        : 'text-[color:var(--arena-muted)] hover:text-[color:var(--arena-sand)] hover:bg-white/[0.04]'
                    }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Tab panels --}}
    @foreach($tabs as $key => $label)
        <div
            id="{{ $id }}-panel-{{ $key }}"
            role="tabpanel"
            data-arena-tab-panel="{{ $key }}"
            data-arena-tab-group="{{ $id }}"
            class="{{ $key === $activeTab ? '' : 'hidden' }} mt-6"
            style="animation: {{ $key === $activeTab ? 'arenaFadeIn 0.25s ease-out' : 'none' }}"
        >
            {{ ${$key} ?? '' }}
        </div>
    @endforeach
</div>
