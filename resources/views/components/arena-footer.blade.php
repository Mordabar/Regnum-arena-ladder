<footer class="relative mt-auto border-t border-[color:var(--arena-line)] bg-[linear-gradient(180deg,rgba(18,13,10,0.96),rgba(12,8,6,1))]">
    <div class="mx-auto max-w-7xl px-4 py-10">
        <div class="grid gap-8 md:grid-cols-3">
            <div>
                <x-arena-brand compact />
                <p class="mt-4 max-w-xs text-sm text-[color:var(--arena-muted)]">
                    Sistema competitivo por reino y subclase con ranking automático, anonimato rival y scoring justo.
                </p>
            </div>
            <div>
                <h3 class="font-['Cinzel'] text-sm font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold)]">Navegación</h3>
                <ul class="mt-4 space-y-2 text-sm">
                    <li><a href="{{ route('home') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Inicio</a></li>
                    <li><a href="{{ route('ladder.index') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Ladder</a></li>
                    @auth
                        <li><a href="{{ route('lobby') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Lobby</a></li>
                        <li><a href="{{ route('queue.index') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Buscar combate</a></li>
                        <li><a href="{{ route('matches.index') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Mis matches</a></li>
                    @endauth
                </ul>
            </div>
            <div>
                <h3 class="font-['Cinzel'] text-sm font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold)]">Comunidad</h3>
                <ul class="mt-4 space-y-2 text-sm">
                    <li>
                        <a href="https://discord.com/channels/1410488072155435010/1410488073862643733" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                            Canal de Discord
                        </a>
                    </li>
                    <li>
                        <a href="https://discord.gg/dYeyzd3X" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">
                            <svg class="h-4 w-4" opacity="0.7" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
                            Invitación Discord
                        </a>
                    </li>
                    <li class="text-[color:var(--arena-muted)] text-xs">
                        {{ \App\Models\AppSetting::getValue('season_name', 'Alpha Season') }}
                    </li>
                </ul>
            </div>
        </div>
        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-[color:var(--arena-line)] pt-6">
            <p class="text-xs text-[color:var(--arena-muted)]">
                © {{ date('Y') }} Regnum Arena Ladder — Conquest PvP. Alpha build.
            </p>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <x-arena-realm-icon realm="ignis" size="xs" />
                    <x-arena-realm-icon realm="alsius" size="xs" />
                    <x-arena-realm-icon realm="syrtis" size="xs" />
                </div>
            </div>
        </div>
    </div>
</footer>
