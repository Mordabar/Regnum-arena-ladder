<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MatchReport extends Model
{
    use HasFactory;

    public const EVIDENCE_DISK = 'arena_reports';

    public const STATUSES = [
        'pending_confirmation' => 'Pendiente de confirmacion',
        'confirmed' => 'Confirmado',
        'rejected' => 'Rechazado',
        'disputed' => 'En disputa',
        'admin_resolved' => 'Resuelto por admin',
        'voided' => 'Anulado',
    ];

    protected $fillable = [
        'match_id',
        'reported_by_player_id',
        'reporting_team',
        'claimed_winner_team',
        'claimed_winner_realm',
        'status',
        'encounter_screenshot_path',
        'final_screenshot_path',
        'evidence_paths',
        'reporter_note',
        'confirmed_by_player_id',
        'confirmed_at',
        'rejected_by_player_id',
        'rejected_at',
        'rejection_note',
        'reviewed_by_user_id',
        'reviewed_at',
        'admin_note',
        'resolution_payload',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'evidence_paths' => 'array',
            'resolution_payload' => 'array',
        ];
    }

    public function match()
    {
        return $this->belongsTo(ArenaMatch::class, 'match_id');
    }

    public function reporter()
    {
        return $this->belongsTo(Player::class, 'reported_by_player_id');
    }

    public function confirmer()
    {
        return $this->belongsTo(Player::class, 'confirmed_by_player_id');
    }

    public function rejector()
    {
        return $this->belongsTo(Player::class, 'rejected_by_player_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function getStatusNameAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function evidencePaths(): array
    {
        $evidencePaths = collect($this->evidence_paths ?? [])
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->values();

        if ($evidencePaths->isNotEmpty()) {
            return $evidencePaths->all();
        }

        return collect([
            $this->final_screenshot_path,
            $this->encounter_screenshot_path,
        ])
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function evidenceItems(): array
    {
        $storedEvidence = collect($this->evidence_paths ?? [])
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->values();

        if ($storedEvidence->isNotEmpty()) {
            return $storedEvidence
                ->map(function (string $path, int $index) {
                    $slot = 'evidence-' . ($index + 1);

                    return [
                        'slot' => $slot,
                        'label' => $index === 0
                            ? 'Captura principal'
                            : 'Captura adicional ' . ($index + 1),
                        'path' => $path,
                        'url' => $this->evidenceUrl($slot),
                    ];
                })
                ->all();
        }

        $items = [];

        if ($this->final_screenshot_path) {
            $items[] = [
                'slot' => 'final',
                'label' => 'Captura final',
                'path' => $this->final_screenshot_path,
                'url' => $this->evidenceUrl('final'),
            ];
        }

        if ($this->encounter_screenshot_path && $this->encounter_screenshot_path !== $this->final_screenshot_path) {
            $items[] = [
                'slot' => 'encounter',
                'label' => 'Captura de encuentro',
                'path' => $this->encounter_screenshot_path,
                'url' => $this->evidenceUrl('encounter'),
            ];
        }

        return $items;
    }

    public function evidencePath(string $slot): ?string
    {
        if ($slot === 'primary') {
            return $this->evidencePaths()[0] ?? null;
        }

        if (preg_match('/^evidence-(\d+)$/', $slot, $matches) === 1) {
            $index = max(0, ((int) $matches[1]) - 1);

            return $this->evidencePaths()[$index] ?? null;
        }

        if (ctype_digit($slot)) {
            $index = max(0, ((int) $slot) - 1);

            return $this->evidencePaths()[$index] ?? null;
        }

        return match ($slot) {
            'encounter' => $this->encounter_screenshot_path,
            'final' => $this->final_screenshot_path,
            default => null,
        };
    }

    public function resolveEvidenceDisk(string $slot): ?string
    {
        $path = $this->evidencePath($slot);

        if (!$path) {
            return null;
        }

        foreach ([self::EVIDENCE_DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return self::EVIDENCE_DISK;
    }

    public function evidenceUrl(string $slot): ?string
    {
        if (!$this->evidencePath($slot)) {
            return null;
        }

        return route('matches.report.evidence', [
            'report' => $this,
            'slot' => $slot,
        ]);
    }
}
