<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Los premios de la temporada y quien va ganandolos.
 *
 * El reparto vive en los ajustes, no escrito en el codigo: una temporada puede
 * repartir otra cosa, o mas, o nada, y cambiarlo no puede ser un despliegue.
 */
class SeasonPrizeService
{
    /** Lo que reparte la Alpha Season mientras nadie diga otra cosa. */
    private const POR_DEFECTO = [1 => 10, 2 => 5, 3 => 2];

    private const MONEDA_POR_DEFECTO = 'lingotes de Magnanita';

    /** El orden en que se mira un podio: el ganador en medio y mas alto. */
    public const ORDEN_DEL_PODIO = [2, 1, 3];

    public function activos(): bool
    {
        return (bool) AppSetting::getValue('season_prizes_enabled', true)
            && $this->total() > 0;
    }

    /** @return array<int, int> puesto => cantidad */
    public function reparto(): array
    {
        $reparto = [];

        foreach (self::POR_DEFECTO as $puesto => $defecto) {
            $cantidad = (int) AppSetting::getValue('season_prize_' . $puesto, $defecto);

            if ($cantidad > 0) {
                $reparto[$puesto] = $cantidad;
            }
        }

        return $reparto;
    }

    public function total(): int
    {
        return array_sum($this->reparto());
    }

    public function moneda(): string
    {
        $moneda = trim((string) AppSetting::getValue('season_prize_currency', self::MONEDA_POR_DEFECTO));

        return $moneda === '' ? self::MONEDA_POR_DEFECTO : $moneda;
    }

    /** La letra pequeña: quien puede ganar y con que condiciones. */
    public function bases(): string
    {
        return (string) AppSetting::getValue(
            'season_prize_note',
            'Repartidos entre los tres primeros puestos del ladder.'
        );
    }

    /**
     * El podio: los tres primeros del ladder con su premio al lado.
     *
     * Devuelve los tres puestos SIEMPRE, aunque no haya jugadores todavia: un
     * podio con huecos sigue diciendo lo que se reparte, que es justo lo que se
     * viene a leer el primer dia de temporada.
     *
     * @return Collection<int, array{puesto: int, premio: int, player: Player|null}>
     */
    public function podio(): Collection
    {
        $reparto = $this->reparto();

        // El limite es el puesto MAS ALTO premiado, no cuantos premios hay. Con
        // un reparto salteado -pongamos 1.o y 5.o- contar da dos, y el quinto
        // puesto se quedaria vacio para siempre aunque ese jugador exista.
        $lideres = Player::query()
            ->where('is_active', true)
            ->orderByPublicLadder()
            ->limit($reparto === [] ? 0 : max(array_keys($reparto)))
            ->get(['id', 'character_name', 'realm', 'subclass', 'race', 'gender', 'pl_points', 'is_active', 'deactivated_reason']);

        return collect($reparto)
            ->map(fn (int $premio, int $puesto) => [
                'puesto' => $puesto,
                'premio' => $premio,
                'player' => $lideres->get($puesto - 1),
            ])
            ->values();
    }
}
