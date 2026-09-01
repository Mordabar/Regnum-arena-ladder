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
            $this->purge(true, false);
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
