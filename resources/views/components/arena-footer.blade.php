<footer class="relative mt-auto border-t border-[color:var(--arena-line)] bg-[linear-gradient(180deg,rgba(18,13,10,0.96),rgba(12,8,6,1))]">
    <div class="mx-auto max-w-7xl px-4 py-10">
        <div class="arena-footer-grid">
            <div>
                <x-arena-brand compact />
                <p class="mt-4 max-w-xs text-sm text-[color:var(--arena-muted)]">
                    {{-- "Anonimato rival" a secas dejo de ser cierto al entrar el
                         duelo 1v1, que publica los nombres desde el cruce. --}}
                    Duelos 1v1 y arenas 2v2 y 3v3 en la Zona de Guerra. Ranking automático por PL,
                    anonimato rival en las arenas por equipos y premios cada temporada.
                </p>
            </div>
            <div>
                <h3 class="font-['Cinzel'] text-sm font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold)]">Navegación</h3>
                <ul class="arena-footer-nav mt-4 space-y-2 text-sm">
                    <li><a href="{{ route('home') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Inicio</a></li>
                    <li><a href="{{ route('ladder.index') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Ladder</a></li>
                    <li><a href="{{ route('como-jugar') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Cómo jugar</a></li>
                    <li><a href="{{ route('guia') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Como funciona</a></li>
                    @auth
                        <li><a href="{{ route('lobby') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Lobby y arena</a></li>
                        <li><a href="{{ route('matches.index') }}" class="text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">Mis matches</a></li>
                    @endauth
                </ul>
            </div>
            <div>
                <h3 class="font-['Cinzel'] text-sm font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold)]">Comunidad</h3>
                <ul class="arena-footer-nav mt-4 space-y-2 text-sm">
                    <li>
                        <a href="https://discord.com/channels/1410488072155435010/1410488073862643733" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                            Canal de Discord
                        </a>
                    </li>
                    <li>
                        <a href="{{ \App\Models\AppSetting::getValue('discord_invite_url') ?: 'https://discord.gg/QbTYu3fbvr' }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 text-[color:var(--arena-muted)] transition-colors hover:text-[color:var(--arena-gold-soft)]">
                            <svg class="h-4 w-4" opacity="0.7" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
                            Invitación Discord
                        </a>
                    </li>
                    <li class="text-[color:var(--arena-muted)] text-xs">
                        {{ \App\Models\AppSetting::getValue('season_name', 'Alpha Season') }}
                    </li>
                </ul>
            </div>
            {{-- La app. Dos botones al estilo de las tiendas, que es como la
                 gente reconoce "esto se instala". Llevan a los pasos de cada
                 uno en la pagina de descargas. --}}
            <div>
                <h3 class="font-['Cinzel'] text-sm font-semibold uppercase tracking-[0.2em] text-[color:var(--arena-gold)]">Llévatela</h3>
                <div class="arena-footer-apps">
                    <a href="{{ route('descargas') }}#android" class="arena-footer-app">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.87 3.23a11.43 11.43 0 0 0-9.82 0L5.22 5.92a.38.38 0 0 0-.66.38L6.4 9.48A10.78 10.78 0 0 0 1 18h22a10.78 10.78 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 1.25-1.25A1.25 1.25 0 0 1 7 15.25zm10 0A1.25 1.25 0 1 1 18.25 14 1.25 1.25 0 0 1 17 15.25z"/></svg>
                        <span><small>Descargar para</small><b>Android</b></span>
                    </a>
                    <a href="{{ route('descargas') }}#iphone" class="arena-footer-app">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.6c-.02-2.2 1.8-3.26 1.88-3.31a4.05 4.05 0 0 0-3.18-1.72c-1.35-.14-2.64.8-3.33.8-.69 0-1.74-.78-2.86-.76a4.24 4.24 0 0 0-3.58 2.18c-1.53 2.65-.39 6.57 1.1 8.72.73 1.05 1.6 2.23 2.73 2.19 1.1-.04 1.51-.71 2.84-.71 1.32 0 1.7.71 2.86.69 1.18-.02 1.93-1.07 2.65-2.13a9.4 9.4 0 0 0 1.2-2.47 3.83 3.83 0 0 1-2.31-3.48zM14.2 6.13a3.8 3.8 0 0 0 .88-2.73 3.9 3.9 0 0 0-2.52 1.3 3.64 3.64 0 0 0-.9 2.64 3.22 3.22 0 0 0 2.54-1.21z"/></svg>
                        <span><small>Instalar en</small><b>iPhone</b></span>
                    </a>
                </div>
            </div>
        </div>
        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-[color:var(--arena-line)] pt-6">
            <div class="arena-footer-legal">
                <p>© {{ date('Y') }} Regnum Arena Ladder — Conquest PvP. Alpha build.</p>
                {{-- Que nadie lo confunda con algo oficial. --}}
                <p>
                    Proyecto independiente, hecho por y para la comunidad. No está afiliado, patrocinado ni
                    respaldado por NGE ni por los desarrolladores de Regnum Online. Regnum Online y sus marcas
                    pertenecen a sus respectivos dueños.
                </p>
            </div>
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
