<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\MatchResult;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TestingLabService
{
    public const LEGACY_TEST_DISCORD_ID = '888888888888888888';
    public const LAB_EMAIL_DOMAIN = 'queue-lab.test';
    public const LAB_DISCORD_PREFIX = 'queue-lab-';
    private const NAME_PREFIXES = [
        'Ael', 'Ar', 'Bren', 'Cael', 'Dra', 'Ery', 'Fen', 'Gal', 'Iri', 'Kael',
        'Lun', 'Myr', 'Nyr', 'Or', 'Py', 'Rav', 'Syl', 'Tal', 'Val', 'Zor',
    ];
    private const NAME_SUFFIXES = [
        'anor', 'drel', 'eth', 'ian', 'ion', 'or', 'rak', 'ros', 'thas', 'var',
        'wen', 'yas', 'zar', 'mir', 'loth', 'dun', 'vek', 'riel', 'thor', 'nis',
    ];

    public function testUsersQuery(): Builder
    {
        return User::query()->where(function ($query) {
            $query->where('discord_id', self::LEGACY_TEST_DISCORD_ID)
                ->orWhere('discord_id', 'like', self::LAB_DISCORD_PREFIX . '%')
                ->orWhere('email', 'like', '%@' . self::LAB_EMAIL_DOMAIN);
        });
    }

    public function testPlayersQuery(): Builder
    {
        $userIds = $this->testUsersQuery()->select('id');

        return Player::query()->whereIn('user_id', $userIds);
    }

    public function testPlayerIds(): Collection
    {
        return $this->testPlayersQuery()->pluck('id');
    }

    public function seedRoster(array $countsByRealm, bool $replaceExisting = true): int
    {
        if ($replaceExisting) {
            // Se limpia el rastro entero, no solo los bots: si se borrasen los
            // personajes dejando sus enfrentamientos, el historial quedaria
            // apuntando a jugadores que ya no existen.
            $this->purgeTrace(true);
        }

        $createdPlayers = 0;
        $subclasses = array_keys(Player::SUBCLASSES);

        DB::transaction(function () use ($countsByRealm, $subclasses, &$createdPlayers) {
            foreach (['ignis', 'syrtis', 'alsius'] as $realm) {
                $count = max(0, (int) ($countsByRealm[$realm] ?? 0));

                for ($index = 0; $index < $count; $index++) {
                    $user = User::create($this->buildTestingUserPayload($realm, $index));
                    Player::create($this->buildTestingPlayerPayload(
                        $user->id,
                        $realm,
                        $subclasses[$index % count($subclasses)]
                    ));
                    $createdPlayers++;
                }
            }
        });

        return $createdPlayers;
    }

    public function collectLabMatches(?Collection $playerIds = null, ?int $take = 120): Collection
    {
        $playerIds ??= $this->testPlayerIds();

        if ($playerIds->isEmpty()) {
            return collect();
        }

        $query = ArenaMatch::query()
            ->with(['report.reporter', 'results.player'])
            ->latest('id');

        if ($take !== null) {
            $query->take($take);
        }

        return $query->get()
            ->filter(fn (ArenaMatch $match) => $this->isLabMatch($match, $playerIds))
            ->values();
    }

    public function collectMatchesInvolvingPlayers(Collection $playerIds, ?int $take = 120): Collection
    {
        if ($playerIds->isEmpty()) {
            return collect();
        }

        $query = ArenaMatch::query()
            ->with(['report.reporter', 'results.player'])
            ->latest('id');

        if ($take !== null) {
            $query->take($take);
        }

        return $query->get()
            ->filter(fn (ArenaMatch $match) => $this->matchIntersectsPlayerPool($match, $playerIds))
            ->values();
    }

    public function isLabMatch(ArenaMatch $match, ?Collection $playerIds = null): bool
    {
        $playerIds ??= $this->testPlayerIds();

        if ($playerIds->isEmpty()) {
            return false;
        }

        return $this->matchUsesOnlyPlayerPool($match, $playerIds);
    }

    public function matchUsesOnlyPlayerPool(ArenaMatch $match, Collection $playerIds): bool
    {
        $matchPlayerIds = collect($match->getAllPlayers())
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->filter();

        return $matchPlayerIds->isNotEmpty() && $matchPlayerIds->diff($playerIds)->isEmpty();
    }

    public function matchIntersectsPlayerPool(ArenaMatch $match, Collection $playerIds): bool
    {
        $matchPlayerIds = collect($match->getAllPlayers())
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->filter();

        return $matchPlayerIds->intersect($playerIds)->isNotEmpty();
    }

    /**
     * Borra TODO el rastro de las pruebas, incluidos los enfrentamientos donde
     * un bot jugo contra un personaje real.
     *
     * Esto ultimo es lo que antes bloqueaba el laboratorio: se prohibia borrar
     * mientras existiese un solo match mixto, y como probar el flujo de verdad
     * exige jugar con tu propio personaje, siempre habia alguno y el boton no
     * servia nunca.
     *
     * Borrar esos matches sin mas dejaria al jugador real con los puntos que
     * gano en una prueba, asi que primero se deshace lo que cada uno repartio:
     * se resta el PL y el MMR exactos que guarda cada resultado y se corrigen
     * victorias, derrotas y partidas jugadas. El ladder queda como si esas
     * pruebas no hubiesen existido.
     *
     * @return array{
     *     matches_deleted: int, reports_deleted: int, queues_deleted: int,
     *     players_deleted: int, users_deleted: int,
     *     real_players_restored: int, pl_reverted: float, mmr_reverted: int,
     *     evidence_deleted: int
     * }
     */
    public function purgeTrace(bool $deleteBots = true): array
    {
        $bots = $this->testPlayersQuery()->get();
        $botIds = $bots->pluck('id');
        $users = $this->testUsersQuery()->get();

        $result = [
            'matches_deleted' => 0,
            'reports_deleted' => 0,
            'queues_deleted' => 0,
            'players_deleted' => 0,
            'users_deleted' => 0,
            'real_players_restored' => 0,
            'pl_reverted' => 0.0,
            'mmr_reverted' => 0,
            'evidence_deleted' => 0,
        ];

        if ($botIds->isEmpty()) {
            return $result;
        }

        // Sin limite: si se deja un tope, las pruebas viejas se quedan dentro y
        // el laboratorio nunca acaba de estar limpio.
        $matchIds = $this->collectMatchesInvolvingPlayers($botIds, null)->pluck('id');

        if ($matchIds->isEmpty()) {
            return $this->finishPurge($result, $botIds, $users, $deleteBots);
        }

        DB::transaction(function () use ($matchIds, $botIds, &$result) {
            $result['evidence_deleted'] = $this->deleteEvidenceFiles($matchIds);
            $this->revertScores($matchIds, $botIds, $result);

            $result['reports_deleted'] = MatchReport::query()->whereIn('match_id', $matchIds)->delete();
            MatchResult::query()->whereIn('match_id', $matchIds)->delete();
            $result['matches_deleted'] = ArenaMatch::query()->whereIn('id', $matchIds)->delete();
        });

        return $this->finishPurge($result, $botIds, $users, $deleteBots);
    }

    /** Deshace en los jugadores REALES lo que repartieron esos enfrentamientos. */
    private function revertScores(Collection $matchIds, Collection $botIds, array &$result): void
    {
        $results = MatchResult::query()
            ->whereIn('match_id', $matchIds)
            ->whereNotIn('player_id', $botIds)
            ->get();

        if ($results->isEmpty()) {
            return;
        }

        foreach ($results->groupBy('player_id') as $playerId => $rows) {
            $player = Player::find($playerId);

            if (!$player) {
                continue;
            }

            $pl = (float) $rows->sum('pl_change');
            $mmr = (int) $rows->sum('mmr_change');
            $wins = $rows->where('result', 'win')->count();
            $losses = $rows->where('result', 'loss')->count();

            $player->forceFill([
                // Nunca por debajo de cero: un personaje que jugo solo pruebas
                // acabaria con puntos negativos si se restase a ciegas.
                'pl_points' => max(0, $player->pl_points - $pl),
                'mmr' => max(0, $player->mmr - $mmr),
                'wins' => max(0, $player->wins - $wins),
                'losses' => max(0, $player->losses - $losses),
                'matches_played' => max(0, $player->matches_played - $rows->count()),
            ])->save();

            $result['pl_reverted'] += $pl;
            $result['mmr_reverted'] += $mmr;
            $result['real_players_restored']++;
        }
    }

    /** Las capturas subidas en las pruebas tampoco tienen por que quedarse. */
    private function deleteEvidenceFiles(Collection $matchIds): int
    {
        $deleted = 0;

        foreach (MatchReport::query()->whereIn('match_id', $matchIds)->get() as $report) {
            foreach ((array) ($report->evidence_paths ?? []) as $path) {
                if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function finishPurge(array $result, Collection $botIds, Collection $users, bool $deleteBots): array
    {
        DB::transaction(function () use ($botIds, $users, $deleteBots, &$result) {
            $result['queues_deleted'] = Queue::query()->whereIn('player_id', $botIds)->delete();

            if ($deleteBots) {
                $result['players_deleted'] = Player::query()->whereIn('id', $botIds)->delete();
                $result['users_deleted'] = User::query()->whereIn('id', $users->pluck('id'))->delete();

                return;
            }

            Player::query()->whereIn('id', $botIds)->update([
                'pl_points' => 0,
                'mmr' => 800,
                'matches_played' => 0,
                'wins' => 0,
                'losses' => 0,
                'trust_score' => 100,
                'queue_locked_until' => null,
                'is_active' => true,
            ]);
        });

        return $result;
    }

    public function purge(bool $deleteUsers = true, bool $resetPlayers = false): array
    {
        $users = $this->testUsersQuery()->get();
        $players = $this->testPlayersQuery()->get();
        $playerIds = $players->pluck('id');
        $matchIds = $this->collectLabMatches($playerIds, null)->pluck('id');

        $result = [
            'users_deleted' => 0,
            'players_deleted' => 0,
            'players_reset' => 0,
            'queues_deleted' => 0,
            'matches_deleted' => 0,
        ];

        DB::transaction(function () use ($users, $playerIds, $matchIds, $deleteUsers, $resetPlayers, &$result) {
            if ($matchIds->isNotEmpty()) {
                MatchResult::query()->whereIn('match_id', $matchIds)->delete();
                MatchReport::query()->whereIn('match_id', $matchIds)->delete();
                ArenaMatch::query()->whereIn('id', $matchIds)->delete();
                $result['matches_deleted'] = $matchIds->count();
            }

            if ($playerIds->isNotEmpty()) {
                $result['queues_deleted'] = Queue::query()->whereIn('player_id', $playerIds)->delete();
            }

            if ($deleteUsers) {
                if ($playerIds->isNotEmpty()) {
                    Player::query()->whereIn('id', $playerIds)->delete();
                    $result['players_deleted'] = $playerIds->count();
                }

                if ($users->isNotEmpty()) {
                    User::query()->whereIn('id', $users->pluck('id'))->delete();
                    $result['users_deleted'] = $users->count();
                }

                return;
            }

            if ($resetPlayers && $playerIds->isNotEmpty()) {
                Player::query()->whereIn('id', $playerIds)->update([
                    'pl_points' => 0,
                    'mmr' => 800,
                    'matches_played' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'trust_score' => 100,
                    'queue_locked_until' => null,
                    'is_active' => true,
                ]);

                $result['players_reset'] = $playerIds->count();
            }
        });

        return $result;
    }

    private function buildTestingUserPayload(string $realm, int $index): array
    {
        $label = strtoupper($realm) . '-' . now()->format('His') . '-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        $token = Str::lower(Str::random(8));

        return [
            'discord_id' => self::LAB_DISCORD_PREFIX . Str::uuid(),
            'discord_username' => 'Testing ' . $label,
            'discord_discriminator' => null,
            'discord_avatar' => null,
            'name' => 'Testing ' . $label,
            'email' => $token . '@' . self::LAB_EMAIL_DOMAIN,
        ];
    }

    private function buildTestingPlayerPayload(int $userId, string $realm, string $subclass): array
    {
        return [
            'user_id' => $userId,
            'character_name' => $this->generateRandomCharacterName(),
            'subclass' => $subclass,
            'realm' => $realm,
            // Los bots tambien tienen aspecto: si todos salieran con la misma
            // raza y sexo, el laboratorio no serviria para ver como queda la
            // variedad real de guerreros.
            'race' => array_rand(Player::RACES[$realm] ?? Player::RACES['ignis']),
            'gender' => array_rand(Player::GENDERS),
            'pl_points' => 0,
            'mmr' => random_int(760, 1120),
            'matches_played' => 0,
            'wins' => 0,
            'losses' => 0,
            'trust_score' => random_int(92, 100),
            'queue_locked_until' => null,
            'is_active' => true,
        ];
    }

    private function generateRandomCharacterName(): string
    {
        do {
            $name = self::NAME_PREFIXES[array_rand(self::NAME_PREFIXES)]
                . self::NAME_SUFFIXES[array_rand(self::NAME_SUFFIXES)];
        } while (
            Player::query()->where('character_name', $name)->exists()
            || User::query()->where('name', $name)->exists()
        );

        return $name;
    }
}
