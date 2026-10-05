<?php

namespace App\Services\Matches;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\User;

/**
 * Las sanciones: bloqueo de cola, confianza y su escalado por reincidencia.
 *
 * Parte de lo que era ArenaMatchResultService.
 */
class MatchPenaltyService
{
    private const ABANDON_LOCK_HOURS = 12;
    private const SUPPORT_INFRACTION_LOCK_HOURS = 24;
    private const ABANDON_TRUST_PENALTY = 15;
    private const SUPPORT_INFRACTION_TRUST_PENALTY = 25;
    private const MAX_PENALTY_LOCK_HOURS = 96;
    private const MAX_STRIKE_MULTIPLIER = 4;

    public function applyAbandonmentPenalty(
        Player $player,
        ?ArenaMatch $match = null,
        ?User $admin = null,
        ?string $note = null
    ): void {
        // Sin ranking en juego no hay sancion que aplicar.
        if ($match?->isFriendly()) {
            throw new \RuntimeException('Un amistoso no se sanciona: no mueve el ranking.');
        }

        $this->applyPenalty($player, 'abandonment', $match, $admin, $note);
    }

    public function applyManualQueueLock(Player $player, int $hours = 12, ?string $note = null): void
    {
        $lockAnchor = $player->queue_locked_until && $player->queue_locked_until->isFuture()
            ? $player->queue_locked_until->copy()
            : now();

        $player->update([
            'queue_locked_until' => $lockAnchor->copy()->addHours(max(1, $hours)),
            'queue_lock_reason' => 'manual_lock',
            'last_penalty_type' => 'manual_lock',
            'last_penalty_at' => now(),
        ]);
    }

    public function clearQueueLock(Player $player): void
    {
        $player->update([
            'queue_locked_until' => null,
            'queue_lock_reason' => null,
        ]);
    }

    public function applyPenalty(
        Player $player,
        string $type,
        ?ArenaMatch $match = null,
        ?User $admin = null,
        ?string $note = null
    ): array {
        $profile = $this->penaltyProfile($type);
        $nextStrikes = max(0, (int) $player->penalty_strikes) + 1;
        $lockHours = $this->calculatePenaltyLockHours($profile['base_hours'], $nextStrikes);
        $lockAnchor = $player->queue_locked_until && $player->queue_locked_until->isFuture()
            ? $player->queue_locked_until->copy()
            : now();
        $lockUntil = $lockAnchor->copy()->addHours($lockHours);

        $player->update([
            'queue_locked_until' => $lockUntil,
            'queue_lock_reason' => $type,
            'trust_score' => max(0, $player->trust_score - $profile['trust_penalty']),
            'penalty_strikes' => $nextStrikes,
            'last_penalty_type' => $type,
            'last_penalty_at' => now(),
        ]);

        if ($match) {
            $match->update([
                'notes' => MatchNotes::append(
                    $match->notes,
                    $profile['label'] . ' applied to ' . $player->character_name
                    . ' (' . $lockHours . 'h lock, strike ' . $nextStrikes . ')'
                    . ($note ? ': ' . $note : '')
                ),
            ]);
        }

        if ($admin && $match && $match->report) {
            $match->report->update([
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'admin_note' => $note,
            ]);
        }

        return [
            'type' => $type,
            'lock_hours' => $lockHours,
            'lock_until' => $lockUntil,
            'trust_penalty' => $profile['trust_penalty'],
            'penalty_strikes' => $nextStrikes,
        ];
    }

    private function penaltyProfile(string $type): array
    {
        return match ($type) {
            'support_infraction' => [
                'label' => 'Support infraction',
                'base_hours' => max(1, (int) AppSetting::getValue('support_infraction_lock_hours', self::SUPPORT_INFRACTION_LOCK_HOURS)),
                'trust_penalty' => max(1, (int) AppSetting::getValue('support_infraction_trust_penalty', self::SUPPORT_INFRACTION_TRUST_PENALTY)),
            ],
            default => [
                'label' => 'Abandonment penalty',
                'base_hours' => max(1, (int) AppSetting::getValue('abandonment_lock_hours', self::ABANDON_LOCK_HOURS)),
                'trust_penalty' => max(1, (int) AppSetting::getValue('abandonment_trust_penalty', self::ABANDON_TRUST_PENALTY)),
            ],
        };
    }

    private function calculatePenaltyLockHours(int $baseHours, int $strikeCount): int
    {
        $multiplier = min(self::MAX_STRIKE_MULTIPLIER, max(1, $strikeCount));
        $maxHours = max($baseHours, (int) AppSetting::getValue('penalty_max_lock_hours', self::MAX_PENALTY_LOCK_HOURS));

        return min($maxHours, $baseHours * $multiplier);
    }
}
