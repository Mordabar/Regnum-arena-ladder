<?php

namespace Database\Seeders;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Support\ArenaMode;
use Illuminate\Database\Seeder;

/**
 * Datos de ejemplo para revisar el panel admin con contenido realista.
 *
 * Solo para desarrollo local: nunca se ejecuta en produccion.
 */
class UxAuditSeeder extends Seeder
{
    private const NAMES = [
        'ignis' => ['Kaelthas', 'Vorgrim', 'Ashka', 'Draven', 'Nyx', 'Torvald', 'Ember', 'Sivar'],
        'alsius' => ['Bjorn', 'Freyja', 'Ulfr', 'Sigrun', 'Halvar', 'Astrid', 'Ivar', 'Runa'],
        'syrtis' => ['Elowen', 'Thalor', 'Mira', 'Faelan', 'Sylvi', 'Bran', 'Aine', 'Corwin'],
    ];

    private const SUBCLASSES = ['knight', 'barbarian', 'hunter', 'marksman', 'conjurer', 'warlock'];

    public function run(): void
    {
        $players = collect();

        foreach (self::NAMES as $realm => $names) {
            foreach ($names as $index => $name) {
                $user = User::create([
                    'discord_id' => 'ux-' . $realm . '-' . $index,
                    'discord_username' => strtolower($name) . '#' . (1000 + $index),
                    'name' => $name,
                    'email' => strtolower($name) . '@arena.test',
                ]);

                $players->push(Player::create([
                    'user_id' => $user->id,
                    'character_name' => $name,
                    'subclass' => self::SUBCLASSES[$index % count(self::SUBCLASSES)],
                    'realm' => $realm,
                    'pl_points' => round(mt_rand(0, 420) / 10, 1),
                    'mmr' => 800 + mt_rand(0, 500),
                    'matches_played' => mt_rand(0, 40),
                    'wins' => mt_rand(0, 20),
                    'losses' => mt_rand(0, 20),
                    'trust_score' => mt_rand(60, 100),
                    'is_active' => true,
                ]));
            }
        }

        // Un par de sancionados, para ver ese estado en la pantalla de jugadores.
        $players->take(2)->each(fn (Player $p) => $p->update([
            'queue_locked_until' => now()->addHours(11),
            'queue_lock_reason' => 'abandonment',
            'penalty_strikes' => 1,
            'trust_score' => 55,
        ]));

        $byRealm = $players->groupBy('realm');

        // Gente esperando en cola, en las dos modalidades.
        foreach (['2v2' => 3, '3v3' => 4] as $mode => $count) {
            foreach (['ignis', 'alsius'] as $realm) {
                $byRealm[$realm]->slice(2, $count)->each(fn (Player $p) => Queue::create([
                    'player_id' => $p->id,
                    'queue_type' => 'random',
                    'arena_mode' => $mode,
                    'status' => 'waiting',
                    'estimated_mmr' => $p->mmr,
                    'joined_at' => now()->subMinutes(mt_rand(1, 20)),
                    'expires_at' => now()->addMinutes(mt_rand(5, 25)),
                ]));
            }
        }

        $zones = ['frozen_bridge', 'emerald_pass', 'crimson_canyon', 'central_ruins', 'merchant_coast'];

        // Matches en distintos estados, que es lo que el moderador ve a diario.
        $states = [
            ['status' => 'pending_acceptance', 'mode' => '2v2', 'ago' => 2],
            ['status' => 'in_progress', 'mode' => '3v3', 'ago' => 14],
            ['status' => 'in_progress', 'mode' => '2v2', 'ago' => 25],
            ['status' => 'disputed', 'mode' => '2v2', 'ago' => 90],
            ['status' => 'disputed', 'mode' => '3v3', 'ago' => 140],
            ['status' => 'completed', 'mode' => '2v2', 'ago' => 200],
            ['status' => 'completed', 'mode' => '3v3', 'ago' => 320],
            ['status' => 'void', 'mode' => '2v2', 'ago' => 460],
        ];

        foreach ($states as $index => $state) {
            $size = ArenaMode::teamSize($state['mode']);
            $teamA = $byRealm['ignis']->slice($index % 3, $size)->values();
            $teamB = $byRealm['syrtis']->slice($index % 3, $size)->values();

            if ($teamA->count() < $size || $teamB->count() < $size) {
                continue;
            }

            $match = ArenaMatch::create([
                'match_code' => 'ARENA-' . (3000 + $index),
                'report_token' => strtoupper('UX' . str_pad((string) $index, 8, '0', STR_PAD_LEFT)),
                'queue_mode' => $index % 3 === 0 ? 'premade' : 'random',
                'arena_mode' => $state['mode'],
                'team_a_realm' => 'ignis',
                'team_b_realm' => 'syrtis',
                'team_a' => $this->payload($teamA),
                'team_b' => $this->payload($teamB),
                'zone' => $zones[$index % count($zones)],
                'status' => $state['status'],
                'estimated_mmr_avg' => 1000,
                'started_at' => now()->subMinutes($state['ago']),
                'completed_at' => in_array($state['status'], ['completed', 'void'], true) ? now()->subMinutes($state['ago'] - 10) : null,
                'winner_team' => $state['status'] === 'completed' ? 'team_a' : null,
                'winner_realm' => $state['status'] === 'completed' ? 'ignis' : null,
                'expires_at' => $state['status'] === 'pending_acceptance' ? now()->addMinutes(4) : null,
            ]);

            // Reportes pendientes y disputas: la bandeja de moderacion.
            if (in_array($state['status'], ['in_progress', 'disputed'], true)) {
                MatchReport::create([
                    'match_id' => $match->id,
                    'reported_by_player_id' => $teamA->first()->id,
                    'reporting_team' => 'team_a',
                    'claimed_winner_team' => 'team_a',
                    'claimed_winner_realm' => 'ignis',
                    'status' => $state['status'] === 'disputed' ? 'disputed' : 'pending_confirmation',
                    'encounter_screenshot_path' => 'match-reports/demo/encounter.png',
                    'final_screenshot_path' => 'match-reports/demo/final.png',
                    'evidence_paths' => ['match-reports/demo/final.png'],
                    'reporter_note' => $state['status'] === 'disputed'
                        ? 'Ganamos la ronda decisiva, el rival se desconecto antes del final.'
                        : null,
                    'rejection_note' => $state['status'] === 'disputed'
                        ? 'No es cierto, la captura es de otra partida.'
                        : null,
                ]);
            }
        }
    }

    private function payload($team): array
    {
        return $team->map(fn (Player $p) => [
            'player_id' => $p->id,
            'character_name' => $p->character_name,
            'subclass' => $p->subclass,
            'realm' => $p->realm,
            'discord_id' => (string) $p->user_id,
            'conjurer_role' => $p->subclass === 'conjurer' ? 'offensive' : null,
        ])->all();
    }
}
