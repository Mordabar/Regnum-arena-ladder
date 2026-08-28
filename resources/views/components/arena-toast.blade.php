{{-- Toast notification container — placed once in the layout --}}
<div id="arenaToastContainer" class="fixed bottom-6 right-6 z-[60] flex flex-col-reverse gap-3 pointer-events-none" aria-live="polite"></div>

<template id="arenaToastTemplate">
    <div class="arena-toast pointer-events-auto flex items-start gap-3 rounded-2xl border px-5 py-4 shadow-[0_16px_40px_rgba(0,0,0,0.35)] backdrop-blur-md" style="animation: arenaToastIn 0.3s ease-out" role="alert">
        <span class="arena-toast-icon mt-0.5 shrink-0"></span>
        <div class="min-w-0 flex-1">
            <p class="arena-toast-message text-sm font-medium"></p>
        </div>
        <button type="button" class="shrink-0 rounded-full p-1 text-white/50 transition hover:text-white" onclick="this.closest('.arena-toast').remove()" aria-label="Cerrar">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        </button>
    </div>
</template>
