@props(['match', 'playerId', 'ownSide', 'rivalSide', 'ownRealm', 'rivalRealm', 'esDuelo' => false])

{{-- Terminar un amistoso. El resultado es opcional y solo va al historial: no
     mueve el ranking, asi que no se exige ni quien gano ni capturas. Quien no
     quiera apuntar nada pulsa el boton y listo. --}}
<form method="POST" action="{{ route('matches.friendly.finish') }}" enctype="multipart/form-data" data-sin-recarga class="arena-friendly-form">
    @csrf
    <input type="hidden" name="match_id" value="{{ $match->id }}">
    <input type="hidden" name="player_id" value="{{ $playerId }}">

    <details class="arena-friendly-result">
        <summary>Reporte opcional: no hace falta para terminar</summary>

        <label class="block">
            <span class="mb-2 block text-sm font-medium arena-body-text">Quién ganó (opcional)</span>
            <select name="claimed_winner_team" class="arena-select">
                <option value="">Sin registrar</option>
                <option value="{{ $ownSide }}">{{ $esDuelo ? 'Yo' : 'Tu equipo' }} ({{ \App\Models\ArenaMatch::REALMS[$ownRealm] ?? strtoupper((string) $ownRealm) }})</option>
                <option value="{{ $rivalSide }}">Rival ({{ \App\Models\ArenaMatch::REALMS[$rivalRealm] ?? strtoupper((string) $rivalRealm) }})</option>
                <option value="draw">Empate</option>
            </select>
        </label>

        <label class="block">
            <span class="mb-2 block text-sm font-medium arena-body-text">Capturas (opcional)</span>
            <input type="file" name="evidence_files[]" accept="image/*" class="arena-field text-sm" multiple>
            <span class="mt-2 block text-xs text-[color:var(--arena-muted)] arena-body-text">Hasta 3 imágenes.</span>
        </label>

        <label class="block">
            <span class="mb-2 block text-sm font-medium arena-body-text">Comentario (opcional)</span>
            <textarea name="reporter_note" rows="2" maxlength="500" class="arena-textarea w-full"></textarea>
        </label>
    </details>

    <button type="submit" class="arena-btn w-full"><x-arena-icon name="send" class="h-4 w-4 shrink-0" />Terminar amistoso</button>
</form>
