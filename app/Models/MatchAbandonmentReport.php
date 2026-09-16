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
        'reviewed_by_admin',
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
     * Estados en los que el combate ya termino y sus capturas dejan de dar
     * ventaja.
     *
     * Coincide valor por valor con MatchLineupService::REVEAL_STATUSES y aun
     * asi vive aparte, a proposito. Son dos reglas distintas que hoy responden
     * igual: alli se decide quien ve el NOMBRE del rival -y ese metodo tambien
     * dice que si en los duelos, donde los nombres son publicos desde el
     * cruce-, y aqui quien ve una CAPTURA tomada a mitad de la pelea. Si las
     * dos compartieran constante, añadir un estado por una decision de
     * anonimato abriria en silencio la puerta de las capturas.
     *
     * @var list<string>
     */
    private const ESTADOS_CERRADOS = ['completed', 'disputed', 'void', 'abandoned', 'cancelled'];

    /**
     * Si un jugador puede leer el motivo y abrir las capturas de este aviso.
     *
     * Mientras el combate sigue abierto, no. Las capturas de un aviso se toman
     * A MITAD de la pelea, al reves que las del reporte de resultado, que solo
     * existen cuando ya termino. Enseñarlas al bando contrario en directo le
     * regala la pantalla del enemigo: vida, posicion, quien queda en pie. El
     * anonimato del rival se cuidaba en los nombres y se escapaba entero por
     * aqui.
     *
     * Con el enfrentamiento cerrado se abre para todos los que lo jugaron, que
     * es cuando hace falta para defenderse de una acusacion. En curso, lo leen
     * quien lo escribio y el señalado: a alguien acusado hay que decirle de que
     * se le acusa, y una frase como "se desconecto al minuto dos" no da ninguna
     * ventaja en la pelea.
     *
     * Las capturas del aviso son otra cosa y tienen su propia puerta, mas
     * estrecha: ver evidenciaVisibleParaJugador().
     */
    public function visibleParaJugador(?int $playerId, ArenaMatch $match): bool
    {
        if ($playerId === null) {
            return false;
        }

        // Cerrado: ya no hay ventaja que robar.
        if (in_array($match->status, self::ESTADOS_CERRADOS, true)) {
            return true;
        }

        // En curso: solo quien lo escribio y quien esta señalado.
        return (int) $this->reported_by_player_id === $playerId
            || (int) $this->accused_player_id === $playerId;
    }

    /**
     * Si un jugador puede ABRIR las capturas de este aviso.
     *
     * Mas estrecha que leer el motivo, y por un motivo concreto: estas capturas
     * se toman A MITAD de la pelea, al reves que las del reporte de resultado,
     * que solo existen cuando ya termino. Una frase no da ventaja; una captura
     * en directo es la pantalla del enemigo -vida, posicion, quien queda en
     * pie-, y con ella se pelea.
     *
     * Por eso, mientras el combate sigue vivo, no vale ser "el señalado": se
     * mira el BANDO. Lo abre quien escribio el aviso y quien juegue de su lado.
     * El acusado que sea rival lee la acusacion igual y ve las pruebas cuando
     * el combate cierre, que es cuando le hacen falta para defenderse.
     *
     * La puerta llevaba abierta desde el principio -en 2v2 el rival acusado ya
     * podia descargarlas- y el duelo la convertia en la norma: ahi el unico a
     * quien se puede señalar es el rival, asi que el 100% de los avisos le
     * habria entregado al enemigo la pantalla de quien le acusa.
     */
    public function evidenciaVisibleParaJugador(?int $playerId, ArenaMatch $match): bool
    {
        if (!$this->visibleParaJugador($playerId, $match)) {
            return false;
        }

        if (in_array($match->status, self::ESTADOS_CERRADOS, true)) {
            return true;
        }

        if ((int) $this->reported_by_player_id === $playerId) {
            return true;
        }

        // Si alguno de los dos lados no se resuelve, no se enseña: ante la
        // duda, cerrado.
        $ladoDelAviso = $match->getTeamSideForPlayer((int) $this->reported_by_player_id);
        $ladoDeQuienMira = $match->getTeamSideForPlayer((int) $playerId);

        return $ladoDelAviso !== null
            && $ladoDeQuienMira !== null
            && $ladoDelAviso === $ladoDeQuienMira;
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
