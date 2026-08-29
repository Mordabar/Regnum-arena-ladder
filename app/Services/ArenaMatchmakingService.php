<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Support\ArenaMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ArenaMatchmakingService
{
    /**
     * Estados desde los que un match todavia se puede cancelar. Una vez que
     * pasa a in_progress la partida ya se esta jugando y solo el admin puede
     * deshacerla (markVoid), que si controla los puntos ya otorgados.
     */
    public const CANCELLABLE_STATUSES = ['pending_acceptance', 'accepted'];

    private const PREMADE_DAILY_LIMIT = 3;
    private const REPEAT_PAIR_WINDOW_HOURS = 24;
    private const EXACT_REPEAT_PAIRING_PENALTY = 10000;
    private const HIGH_OVERLAP_PAIRING_PENALTY = 900;
    private const LIGHT_OVERLAP_PAIRING_PENALTY = 180;
    private const TEAM_SEARCH_WINDOW = 10;
    private const TEAM_DUPLICATE_SUBCLASS_PENALTY = 14;
    private const TEAM_TRIPLE_SUBCLASS_PENALTY = 28;
    private const TEAM_EXTRA_CONJURER_PENALTY = 8;
    private const TEAM_ARCHETYPE_PENALTY = 6;
    private const PAIR_CONJURER_MISMATCH_PENALTY = 12;
    private const PAIR_SUPPORT_MISMATCH_PENALTY = 16;
    private const PAIR_SUBCLASS_MISMATCH_WEIGHT = 5;

    private ?Collection $matchesColumnsCache = null;

    public function __construct(
        private readonly DiscordBotService $discordBotService
    ) {
    }

    public function isMatchesSchemaReady(): bool
    {
        $columns = $this->getMatchesColumns();

        if ($columns->isEmpty()) {
            return false;
        }

        $requiredColumns = [
            'match_code',
            'report_token',
            'queue_mode',
            'arena_mode',
            'team_a_realm',
            'team_b_realm',
            'team_a',
            'team_b',
            'zone',
            'status',
            'winner_team',
            'winner_realm',
            'estimated_mmr_avg',
            'accepted_at',
            'started_at',
            'completed_at',
            'reported_at',
            'expires_at',
            'notes',
            'created_at',
            'updated_at',
        ];

        foreach ($requiredColumns as $column) {
            if (!$columns->has($column)) {
                return false;
            }
        }

        $zoneColumn = $columns->get('zone');
        if (is_array($zoneColumn)) {
            $enumOptions = $this->extractEnumOptions((string) ($zoneColumn['type'] ?? ''));
            if (
                $enumOptions !== []
                && collect($enumOptions)->map(fn (string $zone) => ArenaMatch::normalizeZoneKey($zone))->filter()->isEmpty()
            ) {
                return false;
            }
        }

        return true;
    }

    public function processRandomQueue(bool $expirePendingMatches = true): int
    {
        return $this->processQueue($expirePendingMatches);
    }

    public function processQueue(bool $expirePendingMatches = true): int
    {
        if (!$this->isMatchesSchemaReady()) {
            Log::warning('ArenaMatchmakingService skipped: matches schema is not ready.');
            return 0;
        }

        if ($expirePendingMatches) {
            $this->expirePendingAcceptanceMatches(false);
        }

        // Solo se procesan las modalidades encendidas. Si el admin apago todas,
        // enabled() viene vacio y no se arma ningun equipo.
        $enabledModes = ArenaMode::enabled();

        if ($enabledModes === []) {
            Log::warning('ArenaMatchmakingService: no hay modalidades activas, no se procesa la cola.');

            return 0;
        }

        $randomWaitingQueues = Queue::query()
            ->where('queue_type', 'random')
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->whereHas('player', function ($query) {
                // Un jugador sancionado no vuelve a emparejarse aunque su fila
                // de cola siga viva: cancelMatch reencola sin revalidar, y
                // enqueueParty puede lanzar una party creada horas antes.
                $query->where('is_active', true)
                    ->where(function ($lockQuery) {
                        $lockQuery->whereNull('queue_locked_until')
                            ->orWhere('queue_locked_until', '<=', now());
                    });
            })
            ->with(['player.user'])
            ->orderBy('joined_at')
            ->get();

        $premadeWaitingQueues = Queue::query()
            ->where('queue_type', 'premade')
            ->whereIn('arena_mode', $enabledModes)
            ->where('status', 'waiting')
            ->whereNull('match_id')
            ->whereNotNull('team_id')
            ->whereHas('player', function ($query) {
                // Un jugador sancionado no vuelve a emparejarse aunque su fila
                // de cola siga viva: cancelMatch reencola sin revalidar, y
                // enqueueParty puede lanzar una party creada horas antes.
                $query->where('is_active', true)
                    ->where(function ($lockQuery) {
                        $lockQuery->whereNull('queue_locked_until')
                            ->orWhere('queue_locked_until', '<=', now());
                    });
            })
            ->with(['player.user'])
            ->orderBy('joined_at')
            ->get();

        $candidateTeams = collect();

        // Se agrupa primero por modalidad y despues por reino: un equipo nunca
        // puede mezclar jugadores de colas 2v2 y 3v3.
        foreach ($randomWaitingQueues->groupBy('arena_mode') as $arenaMode => $modeQueues) {
            foreach ($modeQueues->groupBy(fn (Queue $queue) => $queue->player->realm) as $realm => $queues) {
                $candidateTeams = $candidateTeams->merge(
                    // resolve() canoniza: la clave del groupBy es el valor crudo
                    // de la BD y los equipos premade se etiquetan normalizados.
                    // Si no coincidieran, random y premade no se emparejarian.
                    $this->buildRealmTeams(ArenaMode::resolve((string) $arenaMode), (string) $realm, $queues)
                );
            }
        }

        $candidateTeams = $candidateTeams->merge(
            $this->buildPremadeTeams($premadeWaitingQueues)
        );

        $pairings = $this->buildMatchPairings($candidateTeams);
        $matchesCreated = 0;

        foreach ($pairings as $pairing) {
            try {
                $match = DB::transaction(function () use ($pairing) {
                    return $this->createArenaMatch($pairing['team_a'], $pairing['team_b']);
                });
            } catch (\Throwable $e) {
                Log::warning('ArenaMatchmakingService skipped stale pairing', [
                'team_a_realm' => $pairing['team_a']['realm'],
                'team_b_realm' => $pairing['team_b']['realm'],
                'team_a_type' => $pairing['team_a']['queue_type'] ?? 'random',
                'team_b_type' => $pairing['team_b']['queue_type'] ?? 'random',
                'message' => $e->getMessage(),
            ]);

                continue;
            }

            if (!$match instanceof ArenaMatch) {
                continue;
            }

            try {
                $this->discordBotService->notifyMatchFound($match);
            } catch (\Throwable $e) {
                Log::error('ArenaMatchmakingService notifyMatchFound failed', [
                    'match_id' => $match->id,
                    'match_code' => $match->match_code,
                    'message' => $e->getMessage(),
                ]);
            }

            $matchesCreated++;
        }

        return $matchesCreated;
    }

    public function expirePendingAcceptanceMatches(bool $rerunMatchmaking = true): int
    {
        if (!$this->isMatchesSchemaReady()) {
            return 0;
        }

        $expiredMatches = ArenaMatch::query()
            ->where('status', 'pending_acceptance')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expiredMatches as $match) {
            $this->cancelMatch($match, 'timeout', null, false);
        }

        if ($rerunMatchmaking && $expiredMatches->isNotEmpty()) {
            $this->processRandomQueue(false);
        }

        return $expiredMatches->count();
    }

    public function countPartyMatchesTodayForPlayers(iterable $playerIds, ?string $arenaMode = null): int
    {
        $arenaMode = ArenaMode::resolve($arenaMode);

        $players = Player::query()
            ->whereIn('id', collect($playerIds)->map(fn ($id) => (int) $id)->all())
            ->get();

        if ($players->count() !== ArenaMode::teamSize($arenaMode)) {
            return 0;
        }

        $signature = $this->buildPartySignatureFromUserIds(
            $players->pluck('user_id')->all()
        );

        return $this->countPartyMatchesToday($signature);
    }

    public function getPremadeDailyLimit(): int
    {
        return $this->premadeDailyLimit();
    }

    public function cancelMatch(
        ArenaMatch $match,
        string $reason = 'timeout',
        ?int $offendingPlayerId = null,
        bool $rerunMatchmaking = true
    ): void {
        if (!$this->isMatchesSchemaReady()) {
            return;
        }

        // Lista blanca en vez de lista negra: un match solo se puede cancelar
        // mientras nadie haya empezado a jugarlo. Con la lista negra anterior,
        // 'in_progress' y 'disputed' NO estaban cubiertos, asi que un jugador
        // que iba perdiendo podia cancelar el match por la ruta de rechazo y
        // escaparse de la derrota, o borrar una disputa antes de que el admin
        // la resolviera. Para deshacer un match ya empezado esta markVoid(),
        // que ademas verifica que no haya resultados puntuados.
        if (!in_array($match->status, self::CANCELLABLE_STATUSES, true)) {
            return;
        }

        DB::transaction(function () use ($match, $reason, $offendingPlayerId) {
            $matchId = (string) $match->id;
            $queues = Queue::query()
                ->where('match_id', $matchId)
                ->whereIn('status', ['matched', 'accepted'])
                ->get();

            $match->update([
                'status' => 'cancelled',
                'expires_at' => null,
                'notes' => trim(($match->notes ?? '') . "\nCancel reason: {$reason}"),
            ]);

            if ($queues->isEmpty()) {
                return;
            }

            $groupedQueues = $queues->groupBy(fn (Queue $queue) => $queue->team_id ?: 'solo-' . $queue->id);

            foreach ($groupedQueues as $queueGroup) {
                $isPremade = $queueGroup->contains(fn (Queue $queue) => $queue->queue_type === 'premade');
                $containsOffender = $offendingPlayerId !== null
                    && $queueGroup->contains(fn (Queue $queue) => (int) $queue->player_id === $offendingPlayerId);
                $fullyAccepted = $queueGroup->every(fn (Queue $queue) => $queue->status === 'accepted');

                if ($reason === 'player_rejected') {
                    if ($containsOffender) {
                        if ($isPremade) {
                            $this->cancelQueueGroup($queueGroup, true);
                        } else {
                            $offenderQueue = $queueGroup->where('player_id', $offendingPlayerId);
                            $this->cancelQueueGroup($offenderQueue, false);

                            $others = $queueGroup->where('player_id', '!=', $offendingPlayerId);
                            if ($others->isNotEmpty()) {
                                Queue::query()
                                    ->whereIn('id', $others->pluck('id'))
                                    ->update([
                                        'status' => 'waiting',
                                        'matched_at' => null,
                                        'expires_at' => now()->addMinutes(30),
                                        'team_id' => null,
                                        'match_id' => null,
                                        'joined_at' => now(),
                                    ]);
                            }
                        }
                    } else {
                        if ($isPremade) {
                            $this->requeuePremadeGroup($queueGroup);
                        } else {
                            Queue::query()
                                ->whereIn('id', $queueGroup->pluck('id'))
                                ->update([
                                    'status' => 'waiting',
                                    'matched_at' => null,
                                    'expires_at' => now()->addMinutes(30),
                                    'team_id' => null,
                                    'match_id' => null,
                                    'joined_at' => now(),
                                ]);
                        }
                    }
                    continue;
                }

                if ($reason === 'timeout' && !$fullyAccepted) {
                    if ($isPremade) {
                        $this->cancelQueueGroup($queueGroup, true);
                    } else {
                        $this->resetRandomQueueGroup($queueGroup);
                    }

                    continue;
                }

                if ($isPremade) {
                    $this->requeuePremadeGroup($queueGroup);
                } else {
                    $this->resetRandomQueueGroup($queueGroup);
                }
            }
        });

        $this->discordBotService->notifyMatchCancelled($match, $reason);

        if ($rerunMatchmaking) {
            $this->processRandomQueue(false);
        }
    }

    private function buildRealmTeams(string $arenaMode, string $realm, Collection $queues): Collection
    {
        $teamSize = ArenaMode::teamSize($arenaMode);

        $available = $queues
            ->sortBy(fn (Queue $queue) => $queue->estimated_mmr ?? $queue->player->mmr ?? 800)
            ->values();

        $teams = collect();

        while ($available->count() >= $teamSize) {
            $teamEntries = $this->findBestRealmTeam($available, $teamSize);

            if ($teamEntries->count() !== $teamSize) {
                break;
            }

            $teams->push([
                'team_id' => (string) Str::uuid(),
                'arena_mode' => $arenaMode,
                'realm' => $realm,
                'queue_type' => 'random',
                'party_signature' => null,
                'avg_mmr' => (int) round($teamEntries->avg(function (Queue $queue) {
                    return $queue->estimated_mmr ?? $queue->player->mmr ?? 800;
                })),
                'entries' => $teamEntries->values(),
                'profile' => $this->buildQueueTeamProfile($teamEntries),
            ]);

            $available = $available
                ->reject(fn (Queue $queue) => $teamEntries->contains('id', $queue->id))
                ->values();
        }

        return $teams;
    }

    private function buildPremadeTeams(Collection $queues): Collection
    {
        return $queues
            // La clave incluye la modalidad para que un mismo team_id no pueda
            // arrastrar entradas de 2v2 y 3v3 al mismo equipo.
            ->groupBy(fn (Queue $queue) => $queue->arena_mode . '|' . $queue->team_id)
            ->map(function (Collection $teamEntries, string $groupKey) {
                $arenaMode = ArenaMode::resolve($teamEntries->first()->arena_mode);
                $teamSize = ArenaMode::teamSize($arenaMode);
                $teamId = str_contains($groupKey, '|') ? explode('|', $groupKey, 2)[1] : $groupKey;

                if ($teamEntries->count() !== $teamSize) {
                    return null;
                }

                $realms = $teamEntries->map(fn (Queue $queue) => (string) $queue->player->realm)->unique()->values();
                if ($realms->count() !== 1) {
                    return null;
                }

                if ($teamEntries->map(fn (Queue $queue) => (int) $queue->player->user_id)->unique()->count() !== $teamSize) {
                    return null;
                }

                $evaluation = $this->evaluateQueueTeam($teamEntries);
                if ($evaluation === null) {
                    return null;
                }

                $partySignature = $this->resolvePartySignature($teamEntries);
                if ($this->countPartyMatchesToday($partySignature) >= $this->premadeDailyLimit()) {
                    return null;
                }

                return [
                    'team_id' => $teamId,
                    'arena_mode' => $arenaMode,
                    'realm' => (string) $realms->first(),
                    'queue_type' => 'premade',
                    'party_signature' => $partySignature,
                    'avg_mmr' => (int) round($teamEntries->avg(function (Queue $queue) {
                        return $queue->estimated_mmr ?? $queue->player->mmr ?? 800;
                    })),
                    'entries' => $teamEntries->values(),
                    'profile' => $evaluation['profile'],
                ];
            })
            ->filter()
            ->values();
    }

    private function findBestRealmTeam(Collection $available, int $teamSize): Collection
    {
        $count = $available->count();

        if ($count < $teamSize) {
            return collect();
        }

        $windowSize = min($count, self::TEAM_SEARCH_WINDOW);
        $bestTeam = null;
        $bestSpread = null;
        $bestScore = null;

        // Se evalua cada combinacion posible dentro de la ventana de busqueda.
        // Con TEAM_SEARCH_WINDOW = 10 son 45 combinaciones en 2v2 y 120 en 3v3.
        foreach ($this->combinationIndexes($windowSize, $teamSize) as $indexes) {
            $team = collect($indexes)->map(fn (int $index) => $available[$index]);

            $evaluation = $this->evaluateQueueTeam($team);
            if ($evaluation === null) {
                continue;
            }

            $mmrs = $team->map(fn (Queue $queue) => $queue->estimated_mmr ?? $queue->player->mmr ?? 800);
            $spread = $mmrs->max() - $mmrs->min();
            $score = $spread + $evaluation['composition_penalty'];

            if (
                $bestTeam === null
                || $score < $bestScore
                || ($score === $bestScore && $spread < $bestSpread)
            ) {
                $bestTeam = $team;
                $bestSpread = $spread;
                $bestScore = $score;
            }
        }

        return $bestTeam ?? collect();
    }

    /**
     * Combinaciones de $pickCount indices distintos tomados de [0, $itemCount),
     * en orden ascendente. Generaliza los bucles anidados que antes asumian
     * equipos de 2.
     *
     * @return \Generator<int, list<int>>
     */
    private function combinationIndexes(int $itemCount, int $pickCount, int $start = 0, array $prefix = []): \Generator
    {
        if ($pickCount <= 0) {
            yield $prefix;

            return;
        }

        for ($index = $start; $index <= $itemCount - $pickCount; $index++) {
            yield from $this->combinationIndexes($itemCount, $pickCount - 1, $index + 1, [...$prefix, $index]);
        }
    }

    private function buildMatchPairings(Collection $candidateTeams): array
    {
        $available = $candidateTeams->values();
        $pairings = [];
        $recentPairHistory = $this->buildRecentPairHistory();
        $recentMatchSnapshots = $this->buildRecentMatchSnapshots();

        while ($available->count() >= 2) {
            $bestPair = null;

            for ($i = 0; $i < $available->count() - 1; $i++) {
                for ($j = $i + 1; $j < $available->count(); $j++) {
                    $teamA = $available[$i];
                    $teamB = $available[$j];

                    // Nunca se enfrenta un equipo de 2v2 contra uno de 3v3.
                    if ($teamA['arena_mode'] !== $teamB['arena_mode']) {
                        continue;
                    }

                    if ($teamA['realm'] === $teamB['realm']) {
                        continue;
                    }

                    $diff = abs($teamA['avg_mmr'] - $teamB['avg_mmr']);
                    $repeatCount = $this->getRepeatPairCount($teamA, $teamB, $recentPairHistory);
                    $repeatPenalty = $repeatCount * self::EXACT_REPEAT_PAIRING_PENALTY;
                    $overlapPenalty = $this->calculateRepeatOverlapPenalty($teamA, $teamB, $recentMatchSnapshots);
                    $compositionPenalty = $this->calculatePairCompositionPenalty($teamA, $teamB);
                    $score = $diff + $repeatPenalty + $overlapPenalty + $compositionPenalty;

                    if (
                        $bestPair === null
                        || $score < $bestPair['score']
                        || ($score === $bestPair['score'] && $diff < $bestPair['diff'])
                    ) {
                        $bestPair = [
                            'diff' => $diff,
                            'score' => $score,
                            'repeat_count' => $repeatCount,
                            'overlap_penalty' => $overlapPenalty,
                            'composition_penalty' => $compositionPenalty,
                            'i' => $i,
                            'j' => $j,
                            'team_a' => $teamA,
                            'team_b' => $teamB,
                        ];
                    }
                }
            }

            if ($bestPair === null) {
                break;
            }

            $pairings[] = [
                'team_a' => $bestPair['team_a'],
                'team_b' => $bestPair['team_b'],
            ];

            $historyKey = $this->pairingHistoryKey($bestPair['team_a'], $bestPair['team_b']);
            $recentPairHistory[$historyKey] = ($recentPairHistory[$historyKey] ?? 0) + 1;

            $available = $available
                ->except([$bestPair['i'], $bestPair['j']])
                ->values();
        }

        return $pairings;
    }

    private function createArenaMatch(array $teamA, array $teamB): ArenaMatch
    {
        $expiresAt = now()->addMinutes((int) AppSetting::getValue('accept_window_minutes', 5));

        $teamAPayload = $this->buildTeamPayload($teamA['entries']);
        $teamBPayload = $this->buildTeamPayload($teamB['entries']);
        $queueIds = $teamA['entries']
            ->pluck('id')
            ->merge($teamB['entries']->pluck('id'))
            ->values();

        $lockedQueues = Queue::query()
            ->whereIn('id', $queueIds)
            ->lockForUpdate()
            ->get(['id', 'status', 'match_id']);

        if (
            $lockedQueues->count() !== $queueIds->count()
            || $lockedQueues->contains(fn (Queue $queue) => $queue->status !== 'waiting' || $queue->match_id !== null)
        ) {
            throw new \RuntimeException('One or more queue entries are no longer available for matchmaking.');
        }

        $arenaMode = ArenaMode::resolve($teamA['arena_mode'] ?? null);

        // El limite diario no necesita filtrarse por modalidad: la firma de
        // party es la lista de user_ids, asi que una dupla ("7-12") y un trio
        // ("7-12-19") nunca comparten firma.
        if (($teamA['queue_type'] ?? 'random') === 'premade' && $this->countPartyMatchesToday((string) ($teamA['party_signature'] ?? '')) >= $this->premadeDailyLimit()) {
            throw new \RuntimeException('Premade party A reached its daily limit.');
        }

        if (($teamB['queue_type'] ?? 'random') === 'premade' && $this->countPartyMatchesToday((string) ($teamB['party_signature'] ?? '')) >= $this->premadeDailyLimit()) {
            throw new \RuntimeException('Premade party B reached its daily limit.');
        }

        $attributes = [
            'match_code' => ArenaMatch::generateMatchCode(),
            'report_token' => ArenaMatch::generateReportToken(),
            'queue_mode' => ($teamA['queue_type'] ?? 'random') === 'premade' && ($teamB['queue_type'] ?? 'random') === 'premade'
                ? 'premade'
                : 'random',
            'arena_mode' => $arenaMode,
            'team_a_realm' => $teamA['realm'],
            'team_b_realm' => $teamB['realm'],
            'team_a' => $teamAPayload,
            'team_b' => $teamBPayload,
            'zone' => $this->pickZone($teamA['realm'], $teamB['realm']),
            'status' => 'pending_acceptance',
            'estimated_mmr_avg' => (int) round(($teamA['avg_mmr'] + $teamB['avg_mmr']) / 2),
            'expires_at' => $expiresAt,
        ];

        $match = ArenaMatch::create(array_merge(
            $attributes,
            $this->buildMatchModeAttributes($teamA, $teamB),
            $this->buildLegacyRealmColumns($teamA['realm'], $teamAPayload, $teamB['realm'], $teamBPayload)
        ));

        Queue::query()
            ->whereIn('id', $teamA['entries']->pluck('id'))
            ->update([
                'status' => 'matched',
                'matched_at' => now(),
                'expires_at' => $expiresAt,
                'team_id' => $teamA['team_id'],
                'match_id' => (string) $match->id,
            ]);

        Queue::query()
            ->whereIn('id', $teamB['entries']->pluck('id'))
            ->update([
                'status' => 'matched',
                'matched_at' => now(),
                'expires_at' => $expiresAt,
                'team_id' => $teamB['team_id'],
                'match_id' => (string) $match->id,
            ]);

        return $match;
    }

    private function buildTeamPayload(Collection $entries): array
    {
        return $entries->map(function (Queue $queue) {
            return [
                'player_id' => $queue->player->id,
                'character_name' => $queue->player->character_name,
                'subclass' => $queue->player->subclass,
                'realm' => $queue->player->realm,
                'discord_id' => (string) ($queue->player->user->discord_id ?? ''),
                'conjurer_role' => $queue->conjurer_role,
            ];
        })->values()->all();
    }

    private function pickZone(string $teamARealm, string $teamBRealm): string
    {
        $activeMatches = ArenaMatch::query()
            ->whereIn('status', ['pending_acceptance', 'accepted', 'in_progress'])
            ->get(['zone', 'team_a_realm', 'team_b_realm']);

        $activeZones = $activeMatches
            ->pluck('zone')
            ->filter()
            ->map(function ($zone) {
                return ArenaMatch::normalizeZoneKey((string) $zone) ?? (string) $zone;
            })
            ->unique()
            ->all();

        $allZones = $this->getCompatibleZonePool();
        $availableZones = collect($allZones)
            ->reject(function (string $zone) use ($activeZones) {
                $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

                return in_array($zoneKey, $activeZones, true);
            })
            ->values()
            ->all();
        if ($availableZones !== []) {
            return $availableZones[array_rand($availableZones)];
        }

        $incomingRealms = [$teamARealm, $teamBRealm];
        sort($incomingRealms);

        $zoneScores = collect($allZones)->mapWithKeys(function (string $zone) use ($activeMatches, $incomingRealms) {
            $zoneKey = ArenaMatch::normalizeZoneKey($zone) ?? $zone;

            $score = $activeMatches->reduce(function (int $carry, ArenaMatch $activeMatch) use ($zoneKey, $incomingRealms) {
                $activeZoneKey = ArenaMatch::normalizeZoneKey((string) $activeMatch->zone) ?? (string) $activeMatch->zone;
                if ($activeZoneKey !== $zoneKey) {
                    return $carry;
                }

                $activeRealms = [(string) $activeMatch->team_a_realm, (string) $activeMatch->team_b_realm];
                sort($activeRealms);

                if ($activeRealms === $incomingRealms) {
                    return $carry + 100;
                }

                $sharedRealms = count(array_intersect($incomingRealms, $activeRealms));

                if ($sharedRealms === 1) {
                    return $carry + 1;
                }

                return $carry + 10;
            }, 0);

            return [$zone => $score];
        });

        $bestScore = (int) $zoneScores->min();
        $pool = $zoneScores
            ->filter(fn (int $score) => $score === $bestScore)
            ->keys()
            ->values()
            ->all();

        return $pool[array_rand($pool)];
    }

    private function getMatchesColumns(): Collection
    {
        if ($this->matchesColumnsCache !== null) {
            return $this->matchesColumnsCache;
        }

        if (!Schema::hasTable('matches')) {
            return $this->matchesColumnsCache = collect();
        }

        return $this->matchesColumnsCache = collect(Schema::getColumns('matches'))->keyBy('name');
    }

    private function buildLegacyRealmColumns(
        string $teamARealm,
        array $teamAPayload,
        string $teamBRealm,
        array $teamBPayload
    ): array {
        $columns = $this->getMatchesColumns();
        if ($columns->isEmpty()) {
            return [];
        }

        $legacyPayloads = [
            'ignis' => [],
            'syrtis' => [],
            'alsius' => [],
        ];

        $legacyPayloads[$teamARealm] = $teamAPayload;
        $legacyPayloads[$teamBRealm] = $teamBPayload;

        $attributes = [];
        foreach ($legacyPayloads as $realm => $payload) {
            $column = 'team_' . $realm;
            if ($columns->has($column)) {
                $attributes[$column] = $payload;
            }
        }

        return $attributes;
    }

    private function buildMatchModeAttributes(array $teamA, array $teamB): array
    {
        $columns = $this->getMatchesColumns();
        if ($columns->isEmpty()) {
            return [];
        }

        $attributes = [];

        if ($columns->has('team_a_queue_type')) {
            $attributes['team_a_queue_type'] = $teamA['queue_type'] ?? 'random';
        }

        if ($columns->has('team_b_queue_type')) {
            $attributes['team_b_queue_type'] = $teamB['queue_type'] ?? 'random';
        }

        if ($columns->has('team_a_party_signature')) {
            $attributes['team_a_party_signature'] = $teamA['party_signature'] ?? null;
        }

        if ($columns->has('team_b_party_signature')) {
            $attributes['team_b_party_signature'] = $teamB['party_signature'] ?? null;
        }

        return $attributes;
    }

    private function resolvePartySignature(Collection $teamEntries): string
    {
        return $this->buildPartySignatureFromUserIds(
            $teamEntries
                ->map(fn (Queue $queue) => (int) $queue->player->user_id)
                ->all()
        );
    }

    private function buildPartySignatureFromUserIds(array $userIds): string
    {
        $userIds = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return implode('-', $userIds);
    }

    private function countPartyMatchesToday(string $partySignature): int
    {
        if ($partySignature === '') {
            return 0;
        }

        $columns = $this->getMatchesColumns();
        if (!$columns->has('team_a_party_signature') || !$columns->has('team_b_party_signature')) {
            return 0;
        }

        $query = ArenaMatch::query()
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', ['cancelled', 'void'])
            ->where(function ($builder) use ($partySignature) {
                $builder->where('team_a_party_signature', $partySignature)
                    ->orWhere('team_b_party_signature', $partySignature);
            });

        return $query->count();
    }

    private function premadeDailyLimit(): int
    {
        return max(1, (int) AppSetting::getValue('premade_daily_limit', self::PREMADE_DAILY_LIMIT));
    }

    private function requeuePremadeGroup(Collection $queueGroup): void
    {
        Queue::query()
            ->whereIn('id', $queueGroup->pluck('id'))
            ->update([
                'status' => 'waiting',
                'matched_at' => null,
                'expires_at' => now()->addMinutes(30),
                'match_id' => null,
                'joined_at' => now(),
            ]);
    }

    private function resetRandomQueueGroup(Collection $queueGroup): void
    {
        $acceptedIds = $queueGroup
            ->where('status', 'accepted')
            ->pluck('id');

        if ($acceptedIds->isNotEmpty()) {
            Queue::query()
                ->whereIn('id', $acceptedIds)
                ->update([
                    'status' => 'waiting',
                    'matched_at' => null,
                    'expires_at' => now()->addMinutes(30),
                    'team_id' => null,
                    'match_id' => null,
                    'joined_at' => now(),
                ]);
        }

        $matchedIds = $queueGroup
            ->where('status', 'matched')
            ->pluck('id');

        if ($matchedIds->isNotEmpty()) {
            Queue::query()
                ->whereIn('id', $matchedIds)
                ->update([
                    'status' => 'cancelled',
                    'matched_at' => null,
                    'expires_at' => null,
                    'team_id' => null,
                    'match_id' => null,
                ]);
        }
    }

    private function cancelQueueGroup(Collection $queueGroup, bool $isPremade): void
    {
        if ($queueGroup->isEmpty()) {
            return;
        }

        Queue::query()
            ->whereIn('id', $queueGroup->pluck('id'))
            ->update([
                'status' => 'cancelled',
                'matched_at' => null,
                'expires_at' => null,
                'team_id' => null,
                'match_id' => null,
            ]);

        if ($isPremade) {
            $playerIds = $queueGroup->pluck('player_id')->unique()->toArray();
            if (!empty($playerIds)) {
                $partyIds = \App\Models\PartyMember::whereIn('player_id', $playerIds)
                    ->pluck('party_id')
                    ->unique()
                    ->toArray();
                
                if (!empty($partyIds)) {
                    \App\Models\Party::whereIn('id', $partyIds)
                        ->where('status', 'queued')
                        ->update(['status' => 'ready']);
                }
            }
        }
    }

    private function getCompatibleZonePool(): array
    {
        $canonicalZones = ArenaMatch::zoneKeys();
        $zoneColumn = $this->getMatchesColumns()->get('zone');

        if (!is_array($zoneColumn)) {
            return $canonicalZones;
        }

        $enumOptions = $this->extractEnumOptions((string) ($zoneColumn['type'] ?? ''));
        if ($enumOptions !== []) {
            $normalizedEnumOptions = collect($enumOptions)
                ->map(function (string $zone) {
                    return ArenaMatch::normalizeZoneKey($zone);
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($normalizedEnumOptions !== []) {
                return $normalizedEnumOptions;
            }
        }

        $maxLength = $this->extractColumnLength((string) ($zoneColumn['type'] ?? ''));
        if ($maxLength === null) {
            return $canonicalZones;
        }

        $compatibleCanonicalZones = array_values(array_filter($canonicalZones, function (string $zone) use ($maxLength) {
            return strlen($zone) <= $maxLength;
        }));

        if ($compatibleCanonicalZones !== []) {
            return $compatibleCanonicalZones;
        }

        $fallbackZones = collect($canonicalZones)
            ->flatMap(fn (string $zone) => $this->getZoneStorageCandidates($zone))
            ->filter(function (string $zone) use ($maxLength) {
                return strlen($zone) <= $maxLength;
            })
            ->unique()
            ->values()
            ->all();

        return $fallbackZones !== [] ? $fallbackZones : $canonicalZones;
    }

    private function extractEnumOptions(string $columnType): array
    {
        if ($columnType === '' || !str_starts_with(strtolower($columnType), 'enum(')) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $columnType, $matches);

        return $matches[1] ?? [];
    }

    private function extractColumnLength(string $columnType): ?int
    {
        if (preg_match('/\((\d+)\)/', $columnType, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function getZoneStorageCandidates(string $canonicalZone): array
    {
        $spaced = str_replace('_', ' ', $canonicalZone);
        $hyphenated = str_replace('_', '-', $canonicalZone);

        return match ($canonicalZone) {
            'central_ruins' => [$canonicalZone, $spaced, $hyphenated, 'centralruins', 'ruins'],
            'emerald_pass' => [$canonicalZone, $spaced, $hyphenated, 'emeraldpass', 'pass'],
            'crimson_canyon' => [$canonicalZone, $spaced, $hyphenated, 'crimsoncanyon', 'canyon'],
            'frozen_bridge' => [$canonicalZone, $spaced, $hyphenated, 'frozenbridge', 'bridge'],
            'merchant_coast' => [$canonicalZone, $spaced, $hyphenated, 'merchantcoast', 'coast'],
            'obsidian_watch' => [$canonicalZone, $spaced, $hyphenated, 'obsidianwatch', 'watch'],
            default => [$canonicalZone, $spaced, $hyphenated],
        };
    }

    private function buildRecentPairHistory(): array
    {
        return ArenaMatch::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subHours(self::REPEAT_PAIR_WINDOW_HOURS))
            ->get()
            ->reduce(function (array $history, ArenaMatch $match) {
                $teamASignature = $this->teamSignatureFromPlayerIds($match->getTeamPlayerIds('team_a'));
                $teamBSignature = $this->teamSignatureFromPlayerIds($match->getTeamPlayerIds('team_b'));

                if ($teamASignature === '' || $teamBSignature === '') {
                    return $history;
                }

                $key = $this->pairingHistoryKeyFromSignatures($teamASignature, $teamBSignature);
                $history[$key] = ($history[$key] ?? 0) + 1;

                return $history;
            }, []);
    }

    private function buildRecentMatchSnapshots(): Collection
    {
        return ArenaMatch::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subHours(self::REPEAT_PAIR_WINDOW_HOURS))
            ->get()
            ->map(function (ArenaMatch $match) {
                return [
                    'arena_mode' => ArenaMode::resolve($match->arena_mode),
                    'team_a_ids' => $match->getTeamPlayerIds('team_a'),
                    'team_b_ids' => $match->getTeamPlayerIds('team_b'),
                ];
            });
    }

    private function getRepeatPairCount(array $teamA, array $teamB, array $recentPairHistory): int
    {
        $key = $this->pairingHistoryKey($teamA, $teamB);

        return (int) ($recentPairHistory[$key] ?? 0);
    }

    private function pairingHistoryKey(array $teamA, array $teamB): string
    {
        $teamASignature = $this->teamSignatureFromEntries($teamA['entries']);
        $teamBSignature = $this->teamSignatureFromEntries($teamB['entries']);

        return $this->pairingHistoryKeyFromSignatures($teamASignature, $teamBSignature);
    }

    private function pairingHistoryKeyFromSignatures(string $teamASignature, string $teamBSignature): string
    {
        $signatures = [$teamASignature, $teamBSignature];
        sort($signatures);

        return implode('|', $signatures);
    }

    private function teamSignatureFromEntries(Collection $entries): string
    {
        $playerIds = $entries
            ->map(fn (Queue $queue) => (int) $queue->player->id)
            ->all();

        return $this->teamSignatureFromPlayerIds($playerIds);
    }

    private function teamSignatureFromPlayerIds(array $playerIds): string
    {
        $playerIds = array_values(array_filter(array_map('intval', $playerIds)));
        sort($playerIds);

        return implode('-', $playerIds);
    }

    private function evaluateQueueTeam(Collection $team): ?array
    {
        // Todas las entradas de un equipo comparten modalidad (se agrupa por
        // arena_mode antes de llegar aqui), asi que la primera define el tamaño.
        $teamSize = ArenaMode::teamSize($team->first()?->arena_mode);

        if ($team->count() !== $teamSize) {
            return null;
        }

        // Un mismo usuario no puede ocupar dos puestos del equipo con dos de
        // sus personajes: fisicamente solo puede jugar uno. buildPremadeTeams
        // ya lo validaba por su cuenta, pero el camino random no lo hacia y es
        // alcanzable (varios personajes por cuenta, reencolados tras cancelar).
        // Al vivir aqui, la regla cubre las dos ramas.
        $userIds = $team->map(fn (Queue $queue) => (int) ($queue->player->user_id ?? 0))->filter();

        if ($userIds->count() !== $teamSize || $userIds->unique()->count() !== $teamSize) {
            return null;
        }

        $profile = $this->buildQueueTeamProfile($team);

        if ($profile['support_conjurers'] > 1 || $profile['invalid_conjurer_roles'] > 0) {
            return null;
        }

        $compositionPenalty = 0;
        foreach ($profile['subclasses'] as $count) {
            if ($count > 1) {
                $compositionPenalty += ($count - 1) * self::TEAM_DUPLICATE_SUBCLASS_PENALTY;
            }

            // Penalizacion extra solo tiene sentido a partir de 3: en 2v2 un
            // equipo entero de la misma subclase ya lo cubre la regla anterior.
            if ($count === $teamSize && $teamSize >= 3) {
                $compositionPenalty += self::TEAM_TRIPLE_SUBCLASS_PENALTY;
            }
        }

        $compositionPenalty += max(0, $profile['conjurer_count'] - 1) * self::TEAM_EXTRA_CONJURER_PENALTY;
        $compositionPenalty += max(0, 2 - $profile['archetype_count']) * self::TEAM_ARCHETYPE_PENALTY;

        return [
            'profile' => $profile,
            'composition_penalty' => $compositionPenalty,
        ];
    }

    private function buildQueueTeamProfile(Collection $team): array
    {
        $subclasses = [];
        $archetypes = [];
        $supportConjurers = 0;
        $conjurerCount = 0;
        $invalidConjurerRoles = 0;

        foreach ($team as $queue) {
            $subclass = (string) $queue->player->subclass;
            $subclasses[$subclass] = ($subclasses[$subclass] ?? 0) + 1;

            $archetype = $this->resolveSubclassArchetype($subclass);
            $archetypes[$archetype] = ($archetypes[$archetype] ?? 0) + 1;

            if ($subclass !== 'conjurer') {
                continue;
            }

            $conjurerCount++;
            if (!in_array($queue->conjurer_role, ['support', 'offensive'], true)) {
                $invalidConjurerRoles++;
                continue;
            }

            if ($queue->conjurer_role === 'support') {
                $supportConjurers++;
            }
        }

        return [
            'subclasses' => $subclasses,
            'unique_subclasses' => count($subclasses),
            'archetype_count' => count($archetypes),
            'conjurer_count' => $conjurerCount,
            'support_conjurers' => $supportConjurers,
            'invalid_conjurer_roles' => $invalidConjurerRoles,
        ];
    }

    private function resolveSubclassArchetype(string $subclass): string
    {
        return match ($subclass) {
            'knight', 'barbarian' => 'frontline',
            'hunter', 'marksman', 'warlock' => 'damage',
            'conjurer' => 'utility',
            default => 'flex',
        };
    }

    private function calculatePairCompositionPenalty(array $teamA, array $teamB): int
    {
        $profileA = $teamA['profile'] ?? $this->buildQueueTeamProfile($teamA['entries']);
        $profileB = $teamB['profile'] ?? $this->buildQueueTeamProfile($teamB['entries']);

        $subclassKeys = collect(array_keys($profileA['subclasses']))
            ->merge(array_keys($profileB['subclasses']))
            ->unique();

        $subclassPenalty = $subclassKeys->reduce(function (int $carry, string $subclass) use ($profileA, $profileB) {
            return $carry + (abs(($profileA['subclasses'][$subclass] ?? 0) - ($profileB['subclasses'][$subclass] ?? 0)) * self::PAIR_SUBCLASS_MISMATCH_WEIGHT);
        }, 0);

        $conjurerPenalty = abs($profileA['conjurer_count'] - $profileB['conjurer_count']) * self::PAIR_CONJURER_MISMATCH_PENALTY;
        $supportPenalty = abs($profileA['support_conjurers'] - $profileB['support_conjurers']) * self::PAIR_SUPPORT_MISMATCH_PENALTY;

        return $subclassPenalty + $conjurerPenalty + $supportPenalty;
    }

    private function calculateRepeatOverlapPenalty(array $teamA, array $teamB, Collection $recentMatchSnapshots): int
    {
        $arenaMode = ArenaMode::resolve($teamA['arena_mode'] ?? null);
        $teamSize = ArenaMode::teamSize($arenaMode);
        $teamAIds = $teamA['entries']->map(fn (Queue $queue) => (int) $queue->player->id)->all();
        $teamBIds = $teamB['entries']->map(fn (Queue $queue) => (int) $queue->player->id)->all();

        return $recentMatchSnapshots->reduce(function (int $carry, array $snapshot) use ($arenaMode, $teamSize, $teamAIds, $teamBIds) {
            // Solo se compara contra partidas de la misma modalidad: el
            // solapamiento de un 3v3 no es equiparable al de un 2v2, porque
            // "cuantos jugadores se repiten" significa cosas distintas.
            if (($snapshot['arena_mode'] ?? ArenaMode::FALLBACK) !== $arenaMode) {
                return $carry;
            }

            $forwardPenalty = $this->calculateOverlapPenalty(
                $this->countPlayerOverlap($teamAIds, $snapshot['team_a_ids']),
                $this->countPlayerOverlap($teamBIds, $snapshot['team_b_ids']),
                $teamSize
            );

            $reversePenalty = $this->calculateOverlapPenalty(
                $this->countPlayerOverlap($teamAIds, $snapshot['team_b_ids']),
                $this->countPlayerOverlap($teamBIds, $snapshot['team_a_ids']),
                $teamSize
            );

            return max($carry, $forwardPenalty, $reversePenalty);
        }, 0);
    }

    /**
     * El umbral alto es "se repite el equipo completo", asi que depende del
     * tamaño: 2 en 2v2 (identico al comportamiento anterior) y 3 en 3v3. Con un
     * 2 fijo, en 3v3 un solapamiento parcial de 2 de 3 se penalizaba al maximo.
     */
    private function calculateOverlapPenalty(int $teamAOverlap, int $teamBOverlap, int $teamSize = 2): int
    {
        if ($teamAOverlap >= $teamSize && $teamBOverlap >= $teamSize) {
            return self::HIGH_OVERLAP_PAIRING_PENALTY;
        }

        if ($teamAOverlap >= 1 && $teamBOverlap >= 1) {
            return self::LIGHT_OVERLAP_PAIRING_PENALTY;
        }

        return 0;
    }

    private function countPlayerOverlap(array $currentPlayers, array $previousPlayers): int
    {
        return count(array_intersect(
            array_map('intval', $currentPlayers),
            array_map('intval', $previousPlayers)
        ));
    }
}
