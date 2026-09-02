{{-- Mapa de la zona y los scripts del enfrentamiento en curso. --}}
@if($currentMatch)
    {{-- ── QUEUE ZONE MAP MODAL ── --}}
    <div id="modal-queue-zone-map" class="fixed inset-0 z-50 items-center justify-center" style="display:none" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" data-modal-close="modal-queue-zone-map"></div>
        <div class="relative mx-4 w-full max-w-3xl rounded-2xl border border-[color:var(--arena-line-strong)] bg-[linear-gradient(180deg,rgba(40,28,20,0.98),rgba(14,10,8,0.99))] p-6 shadow-[0_25px_60px_rgba(0,0,0,0.5)]" style="animation: arenaModalIn 0.2s ease-out">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-[color:var(--arena-gold)] font-['Cinzel']">Zona Asignada al Match</p>
                    <h3 class="mt-1 text-xl font-semibold text-white">{{ $currentMatch->zone_name }}</h3>
                </div>
                <button type="button" class="shrink-0 rounded-full p-1.5 text-[color:var(--arena-muted)] transition-colors hover:bg-white/10 hover:text-white" data-modal-close="modal-queue-zone-map" aria-label="Cerrar">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
            <x-arena-zone-map :zone-key="$currentMatch->zone_key" height="450px" />
        </div>
    </div>
@endif

<script>
    /* ── Tab switching ── */
    (function() {
        const tabs = {
            random: { btn: document.getElementById('tabBtnRandom'), panel: document.getElementById('tab-random') },
            premade: { btn: document.getElementById('tabBtnPremade'), panel: document.getElementById('tab-premade') },
        };

        if (!tabs.random.btn || !tabs.premade.btn) return;

        const activate = (key) => {
            Object.entries(tabs).forEach(([k, { btn, panel }]) => {
                const isActive = k === key;
                panel.classList.toggle('hidden', !isActive);
                if (isActive) panel.style.animation = 'arenaFadeIn 0.25s ease-out';
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                btn.className = btn.className
                    .replace(/bg-\[linear-gradient[^\]]*\]/g, '')
                    .replace(/text-\[color:var\(--arena-gold-soft\)\]/g, '')
                    .replace(/shadow-\[[^\]]*\]/g, '')
                    .replace(/text-\[color:var\(--arena-muted\)\]/g, '')
                    .replace(/hover:text-\[color:var\(--arena-sand\)\]/g, '')
                    .replace(/hover:bg-white\/\[0\.04\]/g, '')
                    .replace(/\s+/g, ' ').trim();
                if (isActive) {
                    btn.classList.add('bg-[linear-gradient(180deg,rgba(63,45,31,0.85),rgba(22,15,11,0.95))]', 'text-[color:var(--arena-gold-soft)]', 'shadow-[0_4px_16px_rgba(0,0,0,0.2),inset_0_1px_0_rgba(255,215,134,0.12)]');
                    localStorage.setItem('arena_queue_active_tab', key); /* Save to localStorage */
                } else {
                    btn.classList.add('text-[color:var(--arena-muted)]', 'hover:text-[color:var(--arena-sand)]', 'hover:bg-white/[0.04]');
                }
            });
        };

        tabs.random.btn.addEventListener('click', () => activate('random'));
        tabs.premade.btn.addEventListener('click', () => activate('premade'));

        /* Load from localStorage or defaults */
        const savedTab = localStorage.getItem('arena_queue_active_tab') || 'random';
        if (savedTab === 'premade') {
            activate('premade');
        }
    })();

    /* ── Conjurer role toggle ── */
    function toggleConjurerRole() {
        const select = document.getElementById('playerSelect');
        const roleDiv = document.getElementById('conjurerRoleDiv');
        const roleSelect = document.getElementById('randomConjurerRole');
        if (!select || !roleDiv || !roleSelect) return;
        const selectedOption = select.options[select.selectedIndex];
        const isConjurer = selectedOption && selectedOption.dataset.subclass === 'conjurer';
        roleDiv.classList.toggle('hidden', !isConjurer);
        roleSelect.disabled = !isConjurer;
        if (!isConjurer) roleSelect.value = 'offensive';
    }

    /* ── Premade builder ── */
    function initializePremadeBuilder() {
        const leaderSelect = document.getElementById('partyLeaderSelect');
        const hint = document.getElementById('premadeRealmHint');
        const summary = document.getElementById('premadeSummary');
        const submitButton = document.getElementById('premadeSubmitButton');
        const endpoint = @json(route('queue.premade.candidates'));
        if (!leaderSelect || !hint || !summary || !submitButton) return;

        // Los slots de compañero salen del servidor: [2] en 2v2, [2,3] en 3v3.
        const companionSlots = @json($premadeSlots);
        const arenaModeLabel = @json($arenaMode);
        const state = { members: {}, debounce: {} };
        companionSlots.forEach(slot => { state.members[slot] = null; });

        const escapeHtml = (v) => String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

        const getLeaderData = () => {
            if (!leaderSelect.value) return null;
            const o = leaderSelect.options[leaderSelect.selectedIndex];
            if (!o) return null;
            return { id: Number(o.value), character_name: o.dataset.characterName, realm: o.dataset.realm, realm_label: o.dataset.realmLabel, subclass: o.dataset.subclass, subclass_label: o.dataset.subclassLabel, user_id: Number(o.dataset.user), owner_label: o.dataset.ownerLabel, is_conjurer: o.dataset.subclass === 'conjurer' };
        };

        const getSelectedPlayerIds = (skip = null) => {
            const ids = [];
            if (leaderSelect.value) ids.push(Number(leaderSelect.value));
            companionSlots.forEach(s => { if (s !== skip) { const i = document.getElementById('partyMemberInput'+s); if (i && i.value) ids.push(Number(i.value)); } });
            return ids;
        };

        const renderSummary = () => {
            const leader = getLeaderData();
            const slots = { 1: leader };
            companionSlots.forEach(slot => { slots[slot] = state.members[slot]; });
            summary.innerHTML = Object.keys(slots).map(k => {
                const p = slots[k];
                if (!p) return '<div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">Slot '+k+' pendiente</div>';
                return '<div class="rounded-2xl border border-[color:var(--arena-line-strong)] bg-black/20 px-4 py-3 text-sm"><p class="font-semibold text-white">'+escapeHtml(p.character_name)+'</p><p class="mt-1 text-[color:var(--arena-muted)]">'+escapeHtml(p.subclass_label)+' - '+escapeHtml(p.realm_label)+'</p><p class="mt-1 text-xs text-[color:var(--arena-muted)]">'+escapeHtml(p.owner_label)+'</p></div>';
            }).join('');
        };

        const updateRoleVisibility = () => {
            const leader = getLeaderData();
            const roleTargets = [{n:document.getElementById('premadeRoleDiv0'),s:document.getElementById('premadeLeaderRole'),p:leader}];
            companionSlots.forEach(slot => {
                roleTargets.push({
                    n: document.getElementById('premadeRoleDiv'+(slot-1)),
                    s: document.getElementById('premadeRole'+slot),
                    p: state.members[slot],
                });
            });
            roleTargets.forEach(c => {
                if (!c.n || !c.s) return;
                const is = !!(c.p && c.p.is_conjurer);
                c.n.classList.toggle('hidden', !is);
                if (!is) c.s.value = 'offensive';
            });
        };

        const updateSubmitState = () => {
            const ready = !!leaderSelect.value && companionSlots.every(slot => !!state.members[slot]);
            submitButton.disabled = !ready;
            submitButton.textContent = ready ? ('Entrar a Premade ' + arenaModeLabel) : 'Completa el equipo para entrar a Premade';
        };

        const renderSelected = (slot) => {
            const c = document.getElementById('premadeSelected'+slot);
            const p = state.members[slot];
            if (!c) return;
            if (!p) { c.classList.add('hidden'); c.innerHTML = ''; return; }
            c.classList.remove('hidden');
            c.innerHTML = '<div class="rounded-2xl border border-[color:var(--arena-line-strong)] bg-black/20 px-4 py-3"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-white">'+escapeHtml(p.character_name)+'</p><p class="mt-1 text-sm text-[color:var(--arena-muted)]">'+escapeHtml(p.subclass_label)+' - '+escapeHtml(p.realm_label)+'</p><p class="mt-1 text-xs text-[color:var(--arena-muted)]">'+escapeHtml(p.owner_label)+' - '+p.mmr+' MMR - '+Number(p.pl_points).toFixed(1)+' PL</p></div><button type="button" class="arena-btn-ghost px-3 py-2 text-xs" data-premade-clear="'+slot+'">Quitar</button></div></div>';
        };

        const clearResults = (slot) => { const c = document.getElementById('premadeResults'+slot); if (c) { c.classList.add('hidden'); c.innerHTML = ''; } };

        const renderResults = (slot, players) => {
            const c = document.getElementById('premadeResults'+slot);
            if (!c) return;
            if (!players.length) { c.classList.remove('hidden'); c.innerHTML = '<div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">No hay compañeros disponibles.</div>'; return; }
            c.classList.remove('hidden');
            c.innerHTML = players.map(p => '<button type="button" class="block w-full rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-left transition hover:border-[color:var(--arena-line-strong)] hover:bg-white/5" data-premade-pick="'+slot+'" data-player="'+encodeURIComponent(JSON.stringify(p))+'"><div class="flex items-start justify-between gap-4"><div><p class="font-semibold text-white">'+escapeHtml(p.character_name)+'</p><p class="mt-1 text-sm text-[color:var(--arena-muted)]">'+escapeHtml(p.subclass_label)+' - '+escapeHtml(p.realm_label)+'</p><p class="mt-1 text-xs text-[color:var(--arena-muted)]">'+escapeHtml(p.owner_label)+'</p></div><div class="text-right text-xs text-[color:var(--arena-muted)]"><p>'+p.mmr+' MMR</p><p>'+Number(p.pl_points).toFixed(1)+' PL</p></div></div></button>').join('');
        };

        const clearMember = (slot, keepText = false) => {
            state.members[slot] = null;
            const h = document.getElementById('partyMemberInput'+slot);
            const s = document.getElementById('premadeSearch'+slot);
            if (h) h.value = '';
            if (s && !keepText) s.value = '';
            renderSelected(slot); updateRoleVisibility(); renderSummary(); updateSubmitState();
        };

        const chooseMember = (slot, player) => {
            state.members[slot] = player;
            const h = document.getElementById('partyMemberInput'+slot);
            const s = document.getElementById('premadeSearch'+slot);
            if (h) h.value = player.id;
            if (s) s.value = player.character_name;
            renderSelected(slot); clearResults(slot); updateRoleVisibility(); renderSummary(); updateSubmitState();
        };

        const searchCandidates = async (slot, query = '') => {
            const leader = getLeaderData();
            if (!leader) { clearResults(slot); return; }
            const params = new URLSearchParams();
            params.set('leader_player_id', leader.id);
            params.set('query', query);
            getSelectedPlayerIds(slot).forEach(id => params.append('selected_player_ids[]', id));
            const c = document.getElementById('premadeResults'+slot);
            if (c) { c.classList.remove('hidden'); c.innerHTML = '<div class="rounded-2xl border border-[color:var(--arena-line)] bg-black/10 px-4 py-3 text-sm text-[color:var(--arena-muted)]">Buscando compañeros...</div>'; }
            try {
                const r = await fetch(endpoint + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!r.ok) throw new Error('fail');
                const d = await r.json();
                renderResults(slot, d.results || []);
            } catch { if (c) c.innerHTML = '<div class="rounded-2xl border border-rose-500/30 bg-rose-900/20 px-4 py-3 text-sm text-rose-200">Error en la búsqueda.</div>'; }
        };

        const syncLeader = () => {
            const leader = getLeaderData();
            companionSlots.forEach(slot => {
                clearMember(slot); clearResults(slot);
                const i = document.getElementById('premadeSearch'+slot);
                if (i) { i.disabled = !leader; i.placeholder = leader ? 'Busca compañero de '+leader.realm_label : 'Primero elige tu líder'; }
            });
            hint.textContent = leader ? 'Premade en '+leader.realm_label+'. Solo verás compañeros del mismo reino y usuarios distintos.' : 'Selecciona primero tu líder.';
            updateRoleVisibility(); renderSummary(); updateSubmitState();
        };

        leaderSelect.addEventListener('change', syncLeader);

        companionSlots.forEach(slot => {
            const input = document.getElementById('premadeSearch'+slot);
            if (!input) return;
            input.addEventListener('focus', () => { if (!input.disabled) searchCandidates(slot, input.value.trim()); });
            input.addEventListener('input', () => {
                if (state.members[slot]) clearMember(slot, true);
                clearTimeout(state.debounce[slot]);
                state.debounce[slot] = setTimeout(() => searchCandidates(slot, input.value.trim()), 240);
            });
        });

        document.addEventListener('click', (e) => {
            const cl = e.target.closest('[data-premade-clear]');
            if (cl) { clearMember(Number(cl.getAttribute('data-premade-clear'))); clearResults(Number(cl.getAttribute('data-premade-clear'))); return; }
            const pk = e.target.closest('[data-premade-pick]');
            if (pk) { chooseMember(Number(pk.getAttribute('data-premade-pick')), JSON.parse(decodeURIComponent(pk.getAttribute('data-player')))); return; }
            companionSlots.forEach(slot => {
                const r = document.getElementById('premadeResults'+slot);
                const i = document.getElementById('premadeSearch'+slot);
                if (r && !r.classList.contains('hidden') && !r.contains(e.target) && !i.contains(e.target)) clearResults(slot);
            });
        });

        syncLeader();
    }

    document.addEventListener('DOMContentLoaded', () => {
        toggleConjurerRole();
        initializePremadeBuilder();
        const randomSelect = document.getElementById('playerSelect');
        if (randomSelect) randomSelect.addEventListener('change', toggleConjurerRole);
    });
</script>

{{-- Shared state-polling component (replaces inline initializeStatePolling) --}}
<x-arena-state-poller :active="$shouldPoll" />
