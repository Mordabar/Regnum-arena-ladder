<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso de que alguien se fue del combate a mitad.
 *
 * No decide nada por si solo: deja el enfrentamiento en disputa y espera a
 * moderacion. Sancionar al señalado con el clic de otro jugador convertiria el
 * boton en un arma, asi que la unica via a la sancion pasa por el panel.
 */
class MatchAbandonmentReport extends Model
{
    use HasFactory;

    /** El mismo disco donde viven las capturas de los reportes de resultado. */
    public const EVIDENCE_DISK = MatchReport::EVIDENCE_DISK;

    public const STATUSES = [
        'pending' => 'Pendiente de revision',
        'confirmed' => 'Abandono confirmado',
        'dismissed' => 'Descartado',
    ];

    protected $fillable = [
        'match_id',
        'reported_by_player_id',
        'accused_player_id',
        'note',
        'evidence_paths',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'evidence_paths' => 'array',
            'reviewed_at' => 'datetime',
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

    public function accused()
    {
        return $this->belongsTo(Player::class, 'accused_player_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function getStatusNameAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * @return array<int, string>
     */
    public function evidencePaths(): array
    {
        return collect($this->evidence_paths ?? [])
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->values()
            ->all();
    }

    public function evidencePath(string $slot): ?string
    {
        if (preg_match('/^(\d+)$/', $slot, $coincidencias) !== 1) {
            return null;
        }

        return $this->evidencePaths()[max(0, ((int) $coincidencias[1]) - 1)] ?? null;
    }

    /**
     * @return array<int, array{slot: string, label: string, path: string, url: string}>
     */
    public function evidenceItems(): array
    {
        return collect($this->evidencePaths())
            ->map(fn (string $path, int $indice) => [
                'slot' => (string) ($indice + 1),
                'label' => 'Captura ' . ($indice + 1),
                'path' => $path,
                'url' => route('matches.abandonment.evidence', [
                    'abandonment' => $this,
                    'slot' => $indice + 1,
                ]),
            ])
            ->all();
    }
}
