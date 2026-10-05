<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\PartyMember;
use App\Models\Player;
use App\Models\Queue;
use App\Services\ArenaMatchmakingService;
use App\Support\ArenaMode;
use App\Support\Competition;
use App\Support\ConjurerRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entrar y salir de la cola en solitario.
 *
 * Salio de QueueHubController, que con mas de 2.000 lineas mezclaba todo esto.
 */
class QueueController extends Controller
{
    public function join(Request $request)
    {
        try {
            $matchmakingService = app(ArenaMatchmakingService::class);

            $arenaMode = ArenaMode::resolve($request->input('arena_mode'));
            $teamSize = ArenaMode::teamSize($arenaMode);

            $request->validate([
                'player_id' => 'required|integer|exists:players,id',
                'arena_mode' => 'nullable|in:' . implode(',', ArenaMode::all()),
                'kind' => 'nullable|in:' . Competition::RANKED . ',' . Competition::FRIENDLY,
                'queue_type' => 'required|in:random,premade',
                'conjurer_role' => 'nullable|in:support,offensive',
                'party_player_ids' => 'nullable|array|size:' . $teamSize,
                'party_player_ids.*' => 'nullable|exists:players,id',
                'party_conjurer_roles' => 'nullable|array|size:' . $teamSize,
                'party_conjurer_roles.*' => 'nullable|in:support,offensive',
            ]);

            if (!ArenaMode::isEnabled($arenaMode)) {
                return back()->withErrors(['error' => 'La modalidad ' . $arenaMode . ' no esta activa en este momento.']);
            }

            // Competitivo o amistoso. Sin pedir ninguno entra al competitivo si
            // esta abierto; si el ladder esta en pausa, al amistoso.
            $kind = Competition::normalize($request->input('kind')) ?? Competition::default();

            if (!Competition::isOpen($kind)) {
                return back()->withErrors(['error' => $kind === Competition::RANKED
                    ? 'El ladder esta en pausa: no hay ninguna temporada abierta. Puedes jugar amistosos.'
                    : 'Los amistosos no estan activos en este momento.']);
            }

            if (!$matchmakingService->isMatchesSchemaReady()) {
                return back()->withErrors([
                    'error' => 'La tabla matches en produccion no tiene aun el esquema MVP v1. Ejecuta la migracion de compatibilidad antes de usar la cola real.',
                ]);
            }

            if ($request->queue_type === 'premade') {
                return back()->withErrors(['error' => 'El emparejamiento premade ahora se maneja mediante Partys. Refresca la página.']);
            }

            $player = Player::findOrFail((int) $request->player_id);
            $this->ensurePlayerCanQueueRandom($player);

            // Falta de rol no es una averia del sistema: es algo que el jugador
            // puede arreglar, asi que se le dice en vez de dejarlo caer en el
            // "no se pudo crear la cola" de mas abajo. En el duelo no se pide:
            // ahi el rol se fija en ofensivo porque no hay a quien apoyar.
            if ($player->subclass === 'conjurer'
                && ArenaMode::supportsPremade($arenaMode)
                && !in_array($request->conjurer_role, ['support', 'offensive'], true)) {
                return back()->withErrors([
                    'error' => 'Elige el rol del conjurador -soporte u ofensivo- antes de entrar a la cola.',
                ]);
            }

            $conjurerRole = ConjurerRole::resolve($player, $request->conjurer_role, $arenaMode);

            // Comprobar y crear dentro de una transaccion con los personajes de
            // la cuenta bloqueados: sin esto, dos peticiones simultaneas (doble
            // clic, dos pestañas) leian "sin cola" a la vez y ambas insertaban,
            // dejando al usuario en dos colas y potencialmente dos matches.
            $created = DB::transaction(function () use ($player, $arenaMode, $conjurerRole, $kind) {
                $lockedPlayerIds = Player::query()
                    ->where('user_id', $player->user_id)
                    ->lockForUpdate()
                    ->pluck('id');

                $existingQueue = Queue::query()
                    ->whereIn('player_id', $lockedPlayerIds)
                    ->whereIn('status', ['waiting', 'matched', 'accepted'])
                    ->exists();

                if ($existingQueue) {
                    return false;
                }

                Queue::create([
                    'player_id' => $player->id,
                    'queue_type' => 'random',
                    'arena_mode' => $arenaMode,
                    'is_ranked' => $kind === Competition::RANKED,
                    'status' => 'waiting',
                    'conjurer_role' => $conjurerRole,
                    'estimated_mmr' => $player->mmr ?? 800,
                    'joined_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                ]);

                return true;
            });

            if (!$created) {
                return back()->withErrors(['error' => 'El usuario ya tiene una cola o match activo.']);
            }

            $matchmakingService->processQueue();

            $playerQueue = Queue::query()
                ->where('player_id', $player->id)
                ->whereIn('status', ['waiting', 'matched', 'accepted'])
                ->latest('id')
                ->first();

            if ($playerQueue?->match_id) {
                return redirect()->route('lobby', ['mode' => $arenaMode, 'kind' => $kind])
                    ->with('success', $player->character_name . ' entro a cola y ya tiene un match real.');
            }

            return back()->with('success', $player->character_name . ' se unio a la cola.');
        } catch (\Throwable $e) {
            Log::error('Arena queue join failed', [
                'user_id' => Auth::id(),
                'player_id' => $request->input('player_id'),
                'queue_type' => $request->input('queue_type'),
                'message' => $e->getMessage(),
            ]);

            $message = 'No se pudo crear o procesar la cola real.';
            if (config('app.debug') || session('arena_admin.authenticated') === true || Auth::user()?->isAdmin()) {
                $message .= ' Detalle: ' . $e->getMessage();
            }

            return back()->withErrors(['error' => $message]);
        }
    }

    public function leave(Request $request)
    {
        $request->validate([
            'player_id' => 'required|exists:players,id',
        ]);

        $player = Player::findOrFail($request->player_id);

        if ($player->user_id !== Auth::id()) {
            return back()->withErrors(['error' => 'No tienes permiso.']);
        }

        $queue = Queue::query()
            ->where('player_id', $player->id)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->latest('joined_at')
            ->first();

        if (!$queue) {
            return back()->withErrors(['error' => 'El personaje no esta en cola.']);
        }

        if ($queue->queue_type === 'premade') {
            $partyMember = PartyMember::where('player_id', $player->id)
                ->whereHas('party', function($q) {
                    $q->where('status', 'queued');
                })
                ->first();

            if ($partyMember) {
                $party = Party::find($partyMember->party_id);
                Queue::query()
                    ->whereIn('player_id', $party->members()->pluck('player_id'))
                    ->where('queue_type', 'premade')
                    ->where('status', 'waiting')
                    ->whereNull('match_id')
                    ->update([
                        'status' => 'cancelled',
                        'team_id' => null,
                        'match_id' => null,
                        'matched_at' => null,
                        'expires_at' => null,
                    ]);
                
                $party->update(['status' => 'ready']);
                return back()->with('success', 'La busqueda de la party ha sido cancelada. Ya pueden reencolar.');
            }
        }

        $queue->update([
            'status' => 'cancelled',
            'team_id' => null,
            'match_id' => null,
            'matched_at' => null,
            'expires_at' => null,
        ]);

        return back()->with('success', $player->character_name . ' salio de la cola random.');
    }

    private function ensurePlayerCanQueueRandom(Player $player): void
    {
        if ($player->user_id !== Auth::id()) {
            throw new \RuntimeException('No tienes permiso para usar este personaje.');
        }

        if (!$player->is_active) {
            throw new \RuntimeException('Este personaje esta deshabilitado: recuperalo desde el lobby para volver a usarlo.');
        }

        if ($player->isQueueLocked()) {
            $reason = $player->queue_lock_reason_name ? ' (' . $player->queue_lock_reason_name . ')' : '';
            throw new \RuntimeException(
                'Este personaje tiene bloqueo activo' . $reason . ' hasta ' . $player->queue_locked_until?->format('Y-m-d H:i')
            );
        }
    }
}
