@props([
    'id' => 'arena-modal',
    'title' => '',
    'variant' => 'default',
    // El mapa de zonas necesita mas ancho que un formulario corto.
    'size' => 'md',
])

@php
    $borderColor = match($variant) {
        'danger' => 'border-rose-500/30',
        'warning' => 'border-amber-500/30',
        'success' => 'border-emerald-500/30',
        default => 'border-[color:var(--arena-line-strong)]',
    };
    $maxWidth = match($size) {
        'lg' => 'max-w-3xl',
        default => 'max-w-lg',
    };
    $titleColor = match($variant) {
        'danger' => 'text-rose-200',
        'warning' => 'text-amber-200',
        'success' => 'text-emerald-200',
        default => 'text-white',
    };
@endphp

@push('arena-modals')
<div
    id="{{ $id }}"
    class="fixed inset-0 z-50 hidden items-center justify-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
>
    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm arena-modal-backdrop" data-modal-close="{{ $id }}"></div>

    {{-- Panel --}}
    <div class="relative mx-4 w-full {{ $maxWidth }} rounded-2xl border {{ $borderColor }} bg-[linear-gradient(180deg,rgba(40,28,20,0.98),rgba(14,10,8,0.99))] p-6 shadow-[0_25px_60px_rgba(0,0,0,0.5)] arena-modal-panel" style="animation: arenaModalIn 0.2s ease-out">
        <div class="flex items-start justify-between gap-4">
            <h3 id="{{ $id }}-title" class="text-xl font-semibold {{ $titleColor }}">{{ $title }}</h3>
            <button type="button" class="shrink-0 rounded-full p-1.5 text-[color:var(--arena-muted)] transition-colors hover:bg-white/10 hover:text-white" data-modal-close="{{ $id }}" aria-label="Cerrar">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <div class="arena-modal-body">
            {{ $slot }}
        </div>
    </div>
</div>
@endpush
