<?php

namespace App\Models;

use App\Support\ArenaMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class ArenaSeason extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_SCHEDULED = 'scheduled';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'enabled_modes',
        'prizes',
        'prize_currency',
        'starts_at',
        'ends_at',
        'auto_close',
        'closed_reason',
        'next_name',
        'next_duration_days',
        'next_starts_at',
        'next_ends_at',
        'reset_on_close',
        'next_prizes_enabled',
        'open_next',
        'prizes_on_open',
    ];

    protected function casts(): array
    {
        return [
            'enabled_modes' => 'array',
            'prizes' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'auto_close' => 'boolean',
            'next_duration_days' => 'integer',
            'next_starts_at' => 'datetime',
            'next_ends_at' => 'datetime',
            'reset_on_close' => 'boolean',
            'next_prizes_enabled' => 'boolean',
            'open_next' => 'boolean',
            'prizes_on_open' => 'boolean',
        ];
    }

    /** La temporada programada para abrirse a futuro, si hay alguna. */
    public static function programada(): ?self
    {
        if (!\App\Support\Esquema::tabla('arena_seasons')) {
            return null;
        }

        return static::query()
            ->where('status', self::STATUS_SCHEDULED)
            ->orderBy('starts_at')
            ->first();
    }

    public static function current(): ?self
    {
        if (!\App\Support\Esquema::tabla('arena_seasons')) {
            return null;
        }

        return static::query()
            ->where('status', self::STATUS_ACTIVE)
            ->latest('starts_at')
            ->first();
    }

    /**
     * Lo que repartio esta temporada, por puesto.
     *
     * @return array<int, int>
     */
    public function reparto(): array
    {
        $reparto = [];

        foreach ((array) ($this->prizes ?? []) as $puesto => $cantidad) {
            if ((int) $cantidad > 0) {
                $reparto[(int) $puesto] = (int) $cantidad;
            }
        }

        ksort($reparto);

        return $reparto;
    }

    public function totalRepartido(): int
    {
        return array_sum($this->reparto());
    }

    public function enabledModes(): array
    {
        return collect($this->enabled_modes ?? [])
            ->map(fn ($mode) => ArenaMode::normalize((string) $mode))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** La zona en la que se escriben y se leen las fechas de temporada. */
    public static function zone(): string
    {
        $zona = (string) config('arena.season_timezone', 'America/Bogota');

        return in_array($zona, timezone_identifiers_list(), true) ? $zona : 'UTC';
    }

    /**
     * Una temporada abierta a la que le toca cerrarse ya: tiene fecha de fin,
     * esa fecha ha llegado y esta marcada para cerrarse sola.
     */
    public function vencida(?CarbonInterface $ahora = null): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->auto_close
            && $this->ends_at !== null
            && $this->ends_at->lte($ahora ?? now());
    }

    /**
     * Donde va la temporada: lo que pinta la barra de progreso.
     *
     * Null si no hay calendario (sin fecha de fin, o una fecha que no cuadra con
     * la de inicio): una barra sin principio o sin final no mide nada, y es
     * mejor no pintarla que pintar una mentira.
     *
     * @return array{
     *     estado: string, porcentaje: float, dia: int, dias: int,
     *     restante: string, hitos: list<array{pos: float, mes: string}>,
     *     inicio: CarbonInterface, fin: CarbonInterface
     * }|null
     */
    public function progreso(?CarbonInterface $ahora = null): ?array
    {
        if ($this->starts_at === null || $this->ends_at === null || !$this->ends_at->gt($this->starts_at)) {
            return null;
        }

        $ahora = $ahora ?? now();
        $zona = self::zone();
        $inicio = $this->starts_at->copy()->setTimezone($zona);
        $fin = $this->ends_at->copy()->setTimezone($zona);

        $total = $fin->getTimestamp() - $inicio->getTimestamp();
        $hecho = $ahora->getTimestamp() - $inicio->getTimestamp();
        $porcentaje = round(max(0, min(100, $hecho / $total * 100)), 1);

        $estado = match (true) {
            $hecho < 0 => 'pendiente',
            $ahora->getTimestamp() >= $fin->getTimestamp() => 'terminada',
            default => 'en_curso',
        };

        // Dias de calendario en la zona de la temporada: el 8 de abril es el dia
        // 1, no el 0, y el ultimo dia cuenta entero.
        $dias = (int) $inicio->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay()) + 1;
        $dia = $estado === 'pendiente'
            ? 0
            : min($dias, (int) $inicio->copy()->startOfDay()->diffInDays($ahora->copy()->setTimezone($zona)->startOfDay()) + 1);

        return [
            'estado' => $estado,
            'porcentaje' => $porcentaje,
            'dia' => $dia,
            'dias' => $dias,
            'restante' => $this->textoRestante($estado, $ahora, $inicio, $fin),
            'hitos' => $this->hitosMensuales($inicio, $fin, $total),
            'inicio' => $inicio,
            'fin' => $fin,
        ];
    }

    private function textoRestante(string $estado, CarbonInterface $ahora, CarbonInterface $inicio, CarbonInterface $fin): string
    {
        if ($estado === 'pendiente') {
            return __('Empieza el :fecha', ['fecha' => self::fechaCorta($inicio)]);
        }

        if ($estado === 'terminada') {
            return 'Temporada terminada';
        }

        $segundos = $fin->getTimestamp() - $ahora->getTimestamp();
        $horas = (int) ceil($segundos / 3600);

        if ($horas > 24) {
            $dias = (int) ceil($segundos / 86400);

            return $dias === 1 ? 'Queda 1 día' : 'Quedan ' . $dias . ' días';
        }

        if ($horas > 1) {
            return 'Quedan ' . $horas . ' horas';
        }

        $minutos = max(1, (int) ceil($segundos / 60));

        return $minutos === 1 ? 'Queda 1 minuto' : 'Quedan ' . $minutos . ' minutos';
    }

    /**
     * El primer dia de cada mes que cae dentro de la temporada, como posicion
     * en la barra. Son las marcas de una regla: sin ellas la barra es un trazo sin
     * escala y no se ve cuanto falta mas alla del porcentaje.
     *
     * @return list<array{pos: float, mes: string}>
     */
    private function hitosMensuales(CarbonInterface $inicio, CarbonInterface $fin, int $total): array
    {
        $hitos = [];
        $mes = $inicio->copy()->startOfMonth()->addMonth();

        while ($mes->lt($fin) && count($hitos) < 36) {
            $pos = ($mes->getTimestamp() - $inicio->getTimestamp()) / $total * 100;

            if ($pos > 0 && $pos < 100) {
                $hitos[] = ['pos' => round($pos, 2), 'mes' => __(self::MESES[$mes->month - 1])];
            }

            $mes->addMonth();
        }

        return $hitos;
    }

    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    private const MESES_LARGOS = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    /** "8 de abril de 2026", sin depender de que el servidor tenga locale. */
    public static function fechaCorta(CarbonInterface $fecha): string
    {
        $fecha = $fecha->copy()->setTimezone(self::zone());

        // Plantilla y meses por el traductor: en inglés es "April 8, 2026".
        return __(':d de :m de :y', [
            'd' => $fecha->day,
            'm' => __(self::MESES_LARGOS[$fecha->month - 1]),
            'y' => $fecha->year,
        ]);
    }

    public function stats()
    {
        return $this->hasMany(SeasonPlayerStat::class, 'season_id');
    }

    public function matches()
    {
        return $this->hasMany(ArenaMatch::class, 'season_id');
    }
}
