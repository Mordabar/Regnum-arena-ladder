<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Party;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\ArenaMatchResultService;
use App\Services\ArenaMatchmakingService;
use App\Services\PlayerCleanupService;
use App\Services\LadderCacheService;
use App\Support\ArenaMode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'players' => Player::query()->count(),
            'active_players' => Player::query()->where('is_active', true)->count(),
            // Habilitados pero sin pasar por el ladder desde hace tiempo. Es una
            // lectura distinta de `is_active`, que solo dice si el personaje
            // esta habilitado para jugar.
            'dormant_players' => Player::query()->where('is_active', true)->dormant()->count(),
            'locked_players' => Player::query()->whereNotNull('queue_locked_until')->where('queue_locked_until', '>', now())->count(),
            'waiting_queues' => Queue::query()->where('status', 'waiting')->count(),
            'pending_acceptance' => ArenaMatch::query()->where('status', 'pending_acceptance')->count(),
            'pending_report_confirmation' => MatchReport::query()->where('status', 'pending_confirmation')->count(),
            'in_progress' => ArenaMatch::query()->where('status', 'in_progress')->count(),
            'disputed' => ArenaMatch::query()->where('status', 'disputed')->count(),
            'completed' => ArenaMatch::query()->where('status', 'completed')->count(),
        ];

        $recentMatches = ArenaMatch::query()->latest('created_at')->take(8)->get();
        $recentReports = MatchReport::query()->with(['match', 'reporter'])->latest('created_at')->take(8)->get();
        return view('admin.dashboard', compact('stats', 'recentMatches', 'recentReports'));
    }

    public function matches(Request $request)
    {
        $query = ArenaMatch::query()->with(['report', 'results']);
        $status = $request->filled('status') ? $request->string('status')->value() : null;
        $search = trim((string) $request->input('q', ''));

        if ($status) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('match_code', 'like', '%' . $search . '%')
                    ->orWhere('report_token', 'like', '%' . $search . '%')
                    ->orWhere('team_a', 'like', '%' . $search . '%')
                    ->orWhere('team_b', 'like', '%' . $search . '%')
                    ->orWhereHas('report', function ($reportQuery) use ($search) {
                        $reportQuery->where('status', 'like', '%' . $search . '%')
                            ->orWhere('reporter_note', 'like', '%' . $search . '%')
                            ->orWhere('rejection_note', 'like', '%' . $search . '%');
                    });
            });
        }

        // El filtro por modalidad aparece con 2v2 y 3v3 conviviendo: sin el, la
        // lista mezcla partidas de 2 y de 3 y no hay forma de auditar una sola.
        $mode = $request->filled('mode') ? $request->string('mode')->value() : null;

        if ($mode && array_key_exists($mode, ArenaMode::MODES)) {
            $query->where('arena_mode', $mode);
        } else {
            $mode = null;
        }

        $matches = $query->latest('created_at')->paginate(20)->withQueryString();

        return view('admin.matches', compact('matches', 'status', 'search', 'mode'));
    }

    public function moderationInbox()
    {
        $pendingConfirmations = MatchReport::query()
            ->with(['match', 'reporter'])
            ->where('status', 'pending_confirmation')
            ->get()
            ->sortBy(function (MatchReport $report) {
                return $report->match?->expires_at?->timestamp ?? PHP_INT_MAX;
            })
            ->values();

        $disputedMatches = ArenaMatch::query()
            ->with(['report.reporter', 'results'])
            ->where('status', 'disputed')
            ->latest('updated_at')
            ->get();

        return view('admin.inbox', compact('pendingConfirmations', 'disputedMatches'));
    }

    public function showMatch(ArenaMatch $match)
    {
        $match->load(['report.reporter', 'report.confirmer', 'report.rejector', 'report.reviewer', 'results.player']);

        return view('admin.match_show', compact('match'));
    }

    public function resolveMatch(Request $request, ArenaMatch $match, ArenaMatchResultService $resultService)
    {
        $validated = $request->validate([
            'action' => 'required|in:confirm_report,force_complete,void,dispute,lock_player,abandonment_walkover,support_infraction',
            'winner_team' => 'nullable|in:team_a,team_b,draw',
            'player_id' => 'nullable|exists:players,id',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            switch ($validated['action']) {
                case 'confirm_report':
                    // Lo mismo que si el rival hubiera pulsado "confirmar", con
                    // su origen anotado. Sirve para ensayar el flujo entero y
                    // para desatascar un reporte cuyo rival no va a contestar.
                    if (!$match->report) {
                        throw new \RuntimeException('Este match no tiene reporte que confirmar.');
                    }

                    $resultService->confirmReportForRival($match->report, $validated['note'] ?? null);
                    $message = 'Reporte confirmado en nombre del rival y ladder actualizado.';
                    break;

                case 'force_complete':
                    // Sin ganador explicito se caia en 'team_a' por defecto, es
                    // decir se repartia PL a favor de un equipo elegido por el
                    // orden de las columnas. Si no hay reporte del que deducirlo,
                    // el admin tiene que decirlo.
                    $winnerTeam = $validated['winner_team']
                        ?? $match->report?->claimed_winner_team;

                    if (!$winnerTeam) {
                        throw new \RuntimeException('Indica el resultado: este match no tiene reporte del que deducir el ganador.');
                    }

                    $resultService->forceComplete(
                        $match,
                        $winnerTeam,
                        null,
                        $validated['note'] ?? null
                    );
                    $message = 'Match cerrado manualmente y ladder actualizado.';
                    break;

                case 'void':
                    $resultService->markVoid($match, null, $validated['note'] ?? null);
                    $message = 'Match marcado como void.';
                    break;

                case 'dispute':
                    $resultService->markDisputed($match, null, $validated['note'] ?? null);
                    $message = 'Match enviado a disputa.';
                    break;

                case 'lock_player':
                    if (empty($validated['player_id'])) {
                        throw new \RuntimeException('Debes seleccionar un jugador.');
                    }

                    $player = Player::findOrFail((int) $validated['player_id']);
                    $resultService->applyAbandonmentPenalty($player, $match, null, $validated['note'] ?? null);
                    $message = 'Penalizacion aplicada al jugador.';
                    break;

                case 'abandonment_walkover':
                    if (empty($validated['player_id'])) {
                        throw new \RuntimeException('Debes seleccionar un jugador.');
                    }

                    $resultService->applyAbandonmentWalkover(
                        $match,
                        (int) $validated['player_id'],
                        null,
                        $validated['note'] ?? null
                    );
                    $message = 'Abandono procesado con derrota automatica para el infractor.';
                    break;

                case 'support_infraction':
                    if (empty($validated['player_id'])) {
                        throw new \RuntimeException('Debes seleccionar un jugador.');
                    }

                    $resultService->applySupportInfraction(
                        $match,
                        (int) $validated['player_id'],
                        null,
                        $validated['note'] ?? null
                    );
                    $message = 'Infraccion de conjurador soporte procesada.';
                    break;

                default:
                    $message = 'Accion no realizada.';
                    break;
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('admin.matches.show', $match)->with('success', $message);
    }

    public function players(Request $request)
    {
        $query = Player::query()->with([
            'user',
            'queues' => fn ($builder) => $builder
                ->whereIn('status', ['waiting', 'matched', 'accepted'])
                ->latest('joined_at'),
        ]);
        $realm = $request->filled('realm') ? $request->string('realm')->value() : null;
        $status = $request->filled('status') ? $request->string('status')->value() : null;

        if ($realm) {
            $query->where('realm', $realm);
        }

        if ($status) {
            if ($status === 'locked') {
                $query->whereNotNull('queue_locked_until')->where('queue_locked_until', '>', now());
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($status === 'deleted') {
                // Los borro su dueno: la fila sigue ahi para no perder su
                // historial de enfrentamientos.
                $query->where('is_active', false)
                    ->where('deactivated_reason', Player::DEACTIVATED_BY_PLAYER);
            } elseif ($status === 'disabled') {
                // Apagados desde el panel. Las filas antiguas, sin motivo
                // guardado, tambien caen aqui: es lo unico que se sabe de ellas.
                $query->where('is_active', false)
                    ->where(fn ($builder) => $builder
                        ->where('deactivated_reason', Player::DEACTIVATED_BY_ADMIN)
                        ->orWhereNull('deactivated_reason'));
            } elseif ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'dormant') {
                // Sin actividad NO es lo mismo que deshabilitado: mide cuanto
                // hace que su dueno no pasa por el ladder.
                $query->dormant();
            }
        }

        // Buscar por nombre: con cientos de personajes, paginar de 25 en 25
        // hasta dar con uno no es una forma razonable de moderar.
        $search = trim((string) $request->input('q', ''));

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('character_name', 'like', '%' . $search . '%')
                    ->orWhereHas('user', fn ($userQuery) => $userQuery
                        ->where('discord_username', 'like', '%' . $search . '%')
                        ->orWhere('discord_id', 'like', '%' . $search . '%'));
            });
        }

        $players = $query
            ->orderByDesc('pl_points')
            ->orderByDesc('mmr')
            ->paginate(25)
            ->withQueryString();

        $dormancyDays = Player::dormancyDays();

        return view('admin.players', compact('players', 'realm', 'status', 'search', 'dormancyDays'));
    }

    public function storePlayer(Request $request)
    {
        $validated = $request->validate([
            'owner_label' => 'required|string|min:3|max:80',
            'owner_email' => 'nullable|email|max:190',
            'character_name' => 'required|string|min:3|max:25|regex:/^[a-zA-Z0-9_\\s-]+$/',
            'subclass' => 'required|in:' . implode(',', array_keys(Player::SUBCLASSES)),
            'realm' => 'required|in:' . implode(',', array_keys(Player::REALMS)),
            'pl_points' => 'nullable|numeric|min:0|max:500',
            'mmr' => 'nullable|integer|min:100|max:5000',
        ]);

        $alreadyExists = Player::query()
            ->where('realm', $validated['realm'])
            ->where('character_name', trim($validated['character_name']))
            ->exists();

        if ($alreadyExists) {
            return back()->withErrors(['error' => 'Ya existe un personaje con ese nombre en el reino seleccionado.']);
        }

        $ownerLabel = trim($validated['owner_label']);
        $user = User::query()->create([
            'discord_id' => 'admin-managed-' . Str::uuid(),
            'discord_username' => $ownerLabel,
            'name' => $ownerLabel,
            'email' => $validated['owner_email'] ?? null,
            'is_admin' => false,
        ]);

        Player::query()->create([
            'user_id' => $user->id,
            'character_name' => trim($validated['character_name']),
            'subclass' => $validated['subclass'],
            'realm' => $validated['realm'],
            'pl_points' => isset($validated['pl_points']) ? round((float) $validated['pl_points'], 1) : 0,
            'mmr' => isset($validated['mmr']) ? (int) $validated['mmr'] : 800,
            'trust_score' => 100,
            'is_active' => true,
            // Un personaje creado a mano tambien necesita aspecto: sin raza el
            // visor no sabria que maniqui dibujar.
            'race' => Player::defaultRace($validated['realm']),
            'gender' => 'male',
        ]);

        app(LadderCacheService::class)->forgetSummary();

        return back()->with('success', 'Jugador creado manualmente desde el panel admin.');
    }

    public function updatePlayer(Request $request, Player $player)
    {
        $validated = $request->validate([
            'action' => 'required|in:lock_12h,unlock_queue,toggle_active,restore_deleted,enqueue_random,remove_from_queue',
            'conjurer_role' => 'nullable|in:support,offensive',
            'arena_mode' => 'nullable|in:' . implode(',', ArenaMode::all()),
        ]);
        $resultService = app(ArenaMatchResultService::class);
        $matchmakingService = app(ArenaMatchmakingService::class);

        $hasActiveQueueState = $player->queues()
            ->whereIn('status', ['waiting', 'matched', 'accepted'])
            ->exists();

        switch ($validated['action']) {
            case 'lock_12h':
                if ($hasActiveQueueState) {
                    return back()->withErrors([
                        'error' => 'No puedes bloquear manualmente a un jugador con cola o match activo desde esta vista.',
                    ]);
                }

                $resultService->applyManualQueueLock($player, 12);
                $message = 'Jugador bloqueado por 12 horas.';
                break;

            case 'unlock_queue':
                $resultService->clearQueueLock($player);
                $message = 'Bloqueo de cola removido.';
                break;

            case 'toggle_active':
                if ($player->is_active && $hasActiveQueueState) {
                    return back()->withErrors([
                        'error' => 'No puedes desactivar un personaje mientras tenga cola o match activo.',
                    ]);
                }

                // Al apagarlo queda constancia de que fue decision del panel, no
                // del jugador: son dos estados distintos y se muestran distinto.
                $player->update($player->is_active
                    ? [
                        'is_active' => false,
                        'deactivated_reason' => Player::DEACTIVATED_BY_ADMIN,
                        'deactivated_at' => now(),
                    ]
                    : [
                        'is_active' => true,
                        'deactivated_reason' => null,
                        'deactivated_at' => null,
                    ]);

                app(LadderCacheService::class)->forgetSummary();
                $message = $player->is_active
                    ? 'Personaje habilitado.'
                    : 'Personaje deshabilitado.';
                break;

            case 'restore_deleted':
                // Devolver a la vida un personaje que borro su dueno. Es la
                // unica via: desde el lobby ya no se puede, justamente para que
                // pase por aqui.
                if (!$player->isDeletedByOwner()) {
                    return back()->withErrors([
                        'error' => 'Este personaje no lo elimino su dueno. Si esta apagado, usa Habilitar.',
                    ]);
                }

                $cleanName = $player->cleanName();

                // Mientras estaba fuera, el jugador pudo crear otro personaje
                // con ese mismo nombre. Devolverselo asi crearia dos iguales.
                $nameTaken = Player::query()
                    ->where('character_name', $cleanName)
                    ->where('realm', $player->realm)
                    ->where('id', '!=', $player->id)
                    ->exists();

                if ($nameTaken) {
                    return back()->withErrors([
                        'error' => "No se puede recuperar: el nombre '{$cleanName}' ya esta ocupado en " . (Player::REALMS[$player->realm] ?? $player->realm) . '. Renombra primero al que lo ocupa.',
                    ]);
                }

                $player->update([
                    'is_active' => true,
                    'deactivated_reason' => null,
                    'deactivated_at' => null,
                    'character_name' => $cleanName,
                ]);

                app(LadderCacheService::class)->forgetSummary();
                $message = "Personaje '{$cleanName}' recuperado y de vuelta en el ranking.";
                break;

            case 'enqueue_random':
                if (!$player->is_active) {
                    return back()->withErrors(['error' => 'No puedes encolar un personaje deshabilitado.']);
                }

                if ($player->isQueueLocked()) {
                    return back()->withErrors(['error' => 'El personaje tiene bloqueo de cola activo.']);
                }

                if ($hasActiveQueueState) {
                    return back()->withErrors(['error' => 'El personaje ya tiene cola o match activo.']);
                }

                if ($player->subclass === 'conjurer' && !in_array($validated['conjurer_role'] ?? null, ['support', 'offensive'], true)) {
                    return back()->withErrors(['error' => 'Debes indicar el rol del conjurador antes de encolarlo.']);
                }

                $manualMode = ArenaMode::resolve($validated['arena_mode'] ?? null);

                if (!ArenaMode::isEnabled($manualMode)) {
                    return back()->withErrors(['error' => 'La modalidad ' . $manualMode . ' no esta activa.']);
                }

                Queue::query()->create([
                    'player_id' => $player->id,
                    'queue_type' => 'random',
                    'arena_mode' => $manualMode,
                    'status' => 'waiting',
                    'conjurer_role' => $player->subclass === 'conjurer' ? $validated['conjurer_role'] : null,
                    'estimated_mmr' => $player->mmr ?? 800,
                    'joined_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                ]);

                $matchmakingService->processQueue();
                $message = 'Jugador encolado manualmente en random ' . $manualMode . '.';
                break;

            case 'remove_from_queue':
                Queue::query()
                    ->where('player_id', $player->id)
                    ->whereIn('status', ['waiting', 'matched', 'accepted'])
                    ->update([
                        'status' => 'cancelled',
                        'team_id' => null,
                        'match_id' => null,
                        'matched_at' => null,
                        'expires_at' => null,
                    ]);
                $message = 'El jugador fue retirado de la cola activa.';
                break;

            default:
                $message = 'Sin cambios.';
                break;
        }

        return back()->with('success', $message);
    }

    public function destroyPlayer(Player $player, PlayerCleanupService $playerCleanupService)
    {
        try {
            $summary = $playerCleanupService->purgePlayer($player);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $message = 'Jugador eliminado del panel admin.';

        if (($summary['matches_deleted'] ?? 0) > 0 || ($summary['results_deleted'] ?? 0) > 0 || ($summary['reports_deleted'] ?? 0) > 0) {
            $message .= ' Se purgaron '
                . ($summary['matches_deleted'] ?? 0) . ' matches, '
                . ($summary['results_deleted'] ?? 0) . ' resultados y '
                . ($summary['reports_deleted'] ?? 0) . ' reportes relacionados.';
        }

        return back()->with('success', $message);
    }

    public function processQueue(ArenaMatchmakingService $matchmakingService)
    {
        $created = $matchmakingService->processQueue();

        return back()->with('success', 'Matchmaking manual ejecutado. Se crearon ' . $created . ' matches.');
    }

    public function expirePendingAcceptance(ArenaMatchmakingService $matchmakingService)
    {
        $expired = $matchmakingService->expirePendingAcceptanceMatches(true);

        return back()->with('success', 'Se expiraron ' . $expired . ' matches pendientes de aceptacion.');
    }

    public function settings()
    {
        $settings = [
            'season_name' => AppSetting::getValue('season_name', 'Alpha Season'),
            'mode_2v2_enabled' => ArenaMode::isEnabled(ArenaMode::TWO_V_TWO),
            'mode_3v3_enabled' => ArenaMode::isEnabled(ArenaMode::THREE_V_THREE),
            'home_tagline' => AppSetting::getValue('home_tagline', 'Conquest PvP por reino y subclase'),
            'rules_excerpt' => AppSetting::getValue('rules_excerpt', 'Random y premade, anonimato rival y ladder automatico.'),
            'support_contact' => AppSetting::getValue('support_contact', ''),
            'discord_invite_url' => AppSetting::getValue('discord_invite_url', ''),
            'discord_server_label' => AppSetting::getValue('discord_server_label', ''),
            'accept_window_minutes' => AppSetting::getValue('accept_window_minutes', 5),
            'hunt_window_minutes' => AppSetting::getValue('hunt_window_minutes', 30),
            'report_confirmation_window_minutes' => AppSetting::getValue('report_confirmation_window_minutes', 15),
            'dispute_auto_void_hours' => AppSetting::getValue('dispute_auto_void_hours', 48),
            'premade_daily_limit' => AppSetting::getValue('premade_daily_limit', 3),
            'random_vs_premade_pl_bonus_pct' => AppSetting::getValue('random_vs_premade_pl_bonus_pct', 25),
            'random_vs_premade_mmr_bonus_pct' => AppSetting::getValue('random_vs_premade_mmr_bonus_pct', 18),
            'premade_vs_random_pl_win_penalty_pct' => AppSetting::getValue('premade_vs_random_pl_win_penalty_pct', 20),
            'premade_vs_random_mmr_win_penalty_pct' => AppSetting::getValue('premade_vs_random_mmr_win_penalty_pct', 14),
            'abandonment_lock_hours' => AppSetting::getValue('abandonment_lock_hours', 12),
            'support_infraction_lock_hours' => AppSetting::getValue('support_infraction_lock_hours', 24),
            'abandonment_trust_penalty' => AppSetting::getValue('abandonment_trust_penalty', 15),
            'support_infraction_trust_penalty' => AppSetting::getValue('support_infraction_trust_penalty', 25),
            'penalty_max_lock_hours' => AppSetting::getValue('penalty_max_lock_hours', 96),
            'inactive_after_days' => AppSetting::getValue('inactive_after_days', 14),
        ];

        $discordConfig = [
            'bot_token_configured' => filled(config('services.discord.bot_token')),
            'guild_id' => config('services.discord.guild_id'),
            'alerts_channel_id' => config('services.discord.alerts_channel_id'),
            'admin_ids' => config('services.discord.admin_ids', []),
        ];

        return view('admin.settings', compact('settings', 'discordConfig'));
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'season_name' => 'required|string|max:120',
            'mode_2v2_enabled' => 'nullable|boolean',
            'mode_3v3_enabled' => 'nullable|boolean',
            'home_tagline' => 'required|string|max:180',
            'rules_excerpt' => 'required|string|max:500',
            'support_contact' => 'nullable|string|max:180',
            'discord_invite_url' => 'nullable|url|max:255',
            'discord_server_label' => 'nullable|string|max:120',
            'accept_window_minutes' => 'required|integer|min:1|max:30',
            'hunt_window_minutes' => 'required|integer|min:5|max:120',
            'report_confirmation_window_minutes' => 'required|integer|min:1|max:60',
            'dispute_auto_void_hours' => 'required|integer|min:1|max:336',
            'premade_daily_limit' => 'required|integer|min:1|max:10',
            'random_vs_premade_pl_bonus_pct' => 'required|numeric|min:0|max:50',
            'random_vs_premade_mmr_bonus_pct' => 'required|numeric|min:0|max:50',
            'premade_vs_random_pl_win_penalty_pct' => 'required|numeric|min:0|max:50',
            'premade_vs_random_mmr_win_penalty_pct' => 'required|numeric|min:0|max:50',
            'abandonment_lock_hours' => 'required|integer|min:1|max:168',
            'support_infraction_lock_hours' => 'required|integer|min:1|max:168',
            'abandonment_trust_penalty' => 'required|integer|min:1|max:100',
            'support_infraction_trust_penalty' => 'required|integer|min:1|max:100',
            'penalty_max_lock_hours' => 'required|integer|min:1|max:336',
            'inactive_after_days' => 'required|integer|min:1|max:365',
        ]);

        $this->applyArenaModeToggles([
            ArenaMode::TWO_V_TWO => $request->boolean('mode_2v2_enabled'),
            ArenaMode::THREE_V_THREE => $request->boolean('mode_3v3_enabled'),
        ]);

        AppSetting::setValue('season_name', $validated['season_name'], 'branding', 'string', true);
        AppSetting::setValue('home_tagline', $validated['home_tagline'], 'branding', 'string', true);
        AppSetting::setValue('rules_excerpt', $validated['rules_excerpt'], 'branding', 'string', true);
        AppSetting::setValue('support_contact', $validated['support_contact'] ?? '', 'branding', 'string', true);
        AppSetting::setValue('discord_invite_url', $validated['discord_invite_url'] ?? '', 'branding', 'string', true);
        AppSetting::setValue('discord_server_label', $validated['discord_server_label'] ?? '', 'branding', 'string', true);
        AppSetting::setValue('accept_window_minutes', $validated['accept_window_minutes'], 'runtime', 'integer', false);
        AppSetting::setValue('hunt_window_minutes', $validated['hunt_window_minutes'], 'runtime', 'integer', false);
        AppSetting::setValue('report_confirmation_window_minutes', $validated['report_confirmation_window_minutes'], 'runtime', 'integer', false);
        AppSetting::setValue('dispute_auto_void_hours', $validated['dispute_auto_void_hours'], 'runtime', 'integer', false);
        AppSetting::setValue('premade_daily_limit', $validated['premade_daily_limit'], 'runtime', 'integer', false);
        AppSetting::setValue('random_vs_premade_pl_bonus_pct', $validated['random_vs_premade_pl_bonus_pct'], 'runtime', 'float', false);
        AppSetting::setValue('random_vs_premade_mmr_bonus_pct', $validated['random_vs_premade_mmr_bonus_pct'], 'runtime', 'float', false);
        AppSetting::setValue('premade_vs_random_pl_win_penalty_pct', $validated['premade_vs_random_pl_win_penalty_pct'], 'runtime', 'float', false);
        AppSetting::setValue('premade_vs_random_mmr_win_penalty_pct', $validated['premade_vs_random_mmr_win_penalty_pct'], 'runtime', 'float', false);
        AppSetting::setValue('abandonment_lock_hours', $validated['abandonment_lock_hours'], 'runtime', 'integer', false);
        AppSetting::setValue('support_infraction_lock_hours', $validated['support_infraction_lock_hours'], 'runtime', 'integer', false);
        AppSetting::setValue('abandonment_trust_penalty', $validated['abandonment_trust_penalty'], 'runtime', 'integer', false);
        AppSetting::setValue('support_infraction_trust_penalty', $validated['support_infraction_trust_penalty'], 'runtime', 'integer', false);
        AppSetting::setValue('penalty_max_lock_hours', $validated['penalty_max_lock_hours'], 'runtime', 'integer', false);
        AppSetting::setValue('inactive_after_days', $validated['inactive_after_days'], 'runtime', 'integer', false);

        return back()->with('success', 'Configuracion guardada.');
    }

    /**
     * Enciende o apaga cada modalidad de forma independiente.
     *
     * Apagar una modalidad no toca los matches ya en curso: esos siguen su
     * flujo normal hasta reportarse. Solo se cierra la puerta a entradas
     * nuevas y se libera a quien quedo esperando en esa cola.
     *
     * @param  array<string, bool>  $desiredStates
     */
    private function applyArenaModeToggles(array $desiredStates): void
    {
        foreach ($desiredStates as $mode => $shouldBeEnabled) {
            $wasEnabled = ArenaMode::isEnabled($mode);

            AppSetting::setValue(
                ArenaMode::settingKey($mode),
                $shouldBeEnabled ? '1' : '0',
                'modes',
                'boolean',
                true
            );

            if ($wasEnabled && !$shouldBeEnabled) {
                $this->releasePlayersWaitingInMode($mode);
            }
        }
    }

    /**
     * Saca de la cola a quienes esperaban en una modalidad recien apagada, para
     * que no queden colgados esperando un match que ya no va a llegar.
     */
    private function releasePlayersWaitingInMode(string $mode): void
    {
        // Solo colas en espera y sin match asignado: si ya tienen match, ese
        // match sigue vivo y debe resolverse normalmente.
        Queue::query()
            ->where('arena_mode', $mode)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->update([
                'status' => 'cancelled',
                'team_id' => null,
                'matched_at' => null,
                'expires_at' => null,
            ]);

        // Las partys no se disuelven: vuelven al estado previo a la cola, igual
        // que cuando expira su busqueda. Si se reactiva la modalidad, siguen ahi.
        // Se excluyen las que ya entraron a un match: ese match sigue vivo y la
        // party debe seguir reflejandolo hasta que se resuelva.
        Party::query()
            ->where('arena_mode', $mode)
            ->where('status', 'queued')
            ->whereDoesntHave('members.player.queues', function ($query) {
                $query->whereIn('status', ['matched', 'accepted']);
            })
            ->get()
            ->each(function (Party $party) {
                $acceptedCount = (int) $party->members()->where('is_accepted_invite', true)->count();

                $party->update([
                    'status' => $acceptedCount >= $party->teamSize() ? 'ready' : 'forming',
                ]);
            });
    }

    public function zones()
    {
        return view('admin.zones_editor');
    }

    public function saveZones(Request $request)
    {
        $validated = $request->validate([
            'zones_json' => 'required|json',
        ]);

        $filepath = public_path('js/arena-zones.js');
        
        // Decode to ensure valid structure
        $zonesData = json_decode($validated['zones_json'], true);
        if (!$zonesData || !is_array($zonesData)) {
            return back()->withErrors(['error' => 'Formato de zonas invalido.']);
        }

        // Validate basic expected format
        foreach ($zonesData as $zone) {
            if (!isset($zone['id']) || !isset($zone['key']) || !isset($zone['name'])) {
                return back()->withErrors(['error' => 'El JSON debe contener id, key, name, coords.']);
            }
        }

        // Keep a backup in storage
        if (file_exists($filepath)) {
            \Illuminate\Support\Facades\Storage::disk('local')->put('arena-zones-backup-' . date('Ymd-His') . '.json', file_get_contents($filepath));
        }

        // Create the valid JS file content
        $jsContent = "window.ARENA_ZONES_CONFIG = " . json_encode($zonesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . ";\n";

        // Save directly to the public asset file
        file_put_contents($filepath, $jsContent);

        return back()->with('success', 'Zonas del mapa guardadas correctamente.');
    }
}
