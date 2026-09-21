<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una zona del mapa: su contorno y sus puntos de encuentro.
 *
 * El contorno es una lista de vertices [y, x] sobre la imagen del mapa, que
 * mide 1086x1086. Los puntos de encuentro son un [y, x] cada uno, o null para
 * "el automatico", que se calcula como el sitio mas interior del contorno.
 */
class ArenaZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'number',
        'name',
        'coords',
        'meeting',
        'meeting_b',
    ];

    protected function casts(): array
    {
        return [
            'coords' => 'array',
            'meeting' => 'array',
            'meeting_b' => 'array',
            'number' => 'integer',
        ];
    }

    /** Si el contorno da para dibujar un poligono. */
    public function tieneContorno(): bool
    {
        return is_array($this->coords) && count($this->coords) >= 3;
    }

    /**
     * Los puntos fijados a mano, en orden, saltandose los huecos.
     *
     * Una zona puede tener el segundo puesto y el primero en automatico. En ese
     * caso la lista trae solo el segundo, y el hueco del primero lo rellena el
     * calculo cuando toca.
     *
     * @return array<int, array{slot: int, punto: array{0: float, 1: float}}>
     */
    public function puntosFijados(): array
    {
        $puntos = [];

        foreach ([1 => $this->meeting, 2 => $this->meeting_b] as $slot => $punto) {
            if (self::esUnPunto($punto)) {
                $puntos[] = ['slot' => $slot, 'punto' => [(float) $punto[0], (float) $punto[1]]];
            }
        }

        return $puntos;
    }

    /** Un punto valido es un par de numeros, ni mas ni menos. */
    public static function esUnPunto(mixed $punto): bool
    {
        return is_array($punto)
            && count($punto) === 2
            && array_keys($punto) === [0, 1]
            && is_numeric($punto[0])
            && is_numeric($punto[1]);
    }

    /**
     * El punto ya en numeros, o null si no lo era.
     *
     * El casteo no es cosmetico. `is_numeric` de PHP acepta la cadena "430",
     * pero `Number.isFinite("430")` del navegador la rechaza: un punto guardado
     * como texto -que es como llega de un formulario- desaparece del mapa
     * mientras el emparejador lo sigue usando. Los dos lados tienen que ver
     * exactamente el mismo punto de encuentro.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function punto(mixed $punto): ?array
    {
        return self::esUnPunto($punto) ? [(float) $punto[0], (float) $punto[1]] : null;
    }

    /**
     * La zona tal y como la espera el mapa del navegador.
     *
     * @return array<string, mixed>
     */
    public function paraElMapa(): array
    {
        $fila = [
            'id' => (int) $this->id,
            'key' => (string) $this->key,
            'name' => (string) $this->name,
            'coords' => $this->coords ?? [],
        ];

        // Las claves ausentes valen "automatico". Mandarlas a null obligaria al
        // JavaScript a distinguir null de ausente, que es un matiz que no hace
        // falta para nada.
        if (($meeting = self::punto($this->meeting)) !== null) {
            $fila['meeting'] = $meeting;
        }

        if (($meetingB = self::punto($this->meeting_b)) !== null) {
            $fila['meeting_b'] = $meetingB;
        }

        return $fila;
    }
}
