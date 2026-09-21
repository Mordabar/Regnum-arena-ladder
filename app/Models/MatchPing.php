<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un aviso rapido dentro de un enfrentamiento.
 *
 * El catalogo esta cerrado y vive aqui: el jugador manda un codigo y el texto
 * lo pone el servidor. Asi nadie escribe lo que quiera, la redaccion se puede
 * cambiar sin tocar lo ya enviado, y traducirlo algun dia es cambiar este
 * array y nada mas.
 */
class MatchPing extends Model
{
    use HasFactory;

    /**
     * Los avisos, en el orden en que salen en pantalla.
     *
     * El orden no es decorativo: arriba lo que se manda nada mas empezar
     * -estoy yendo- y abajo lo que se manda cuando algo se tuerce. Quien busca
     * un boton con prisa lo busca donde lo dejo la ultima vez.
     *
     * Los tonos agrupan: 'camino' es lo que dices mientras te mueves, 'sitio'
     * al llegar, 'aviso' cuando algo pasa, y 'prisa' para meter presion sin
     * que suene a insulto.
     */
    public const CATALOGO = [
        'voy' => ['texto' => 'Voy de camino', 'icono' => '🏃', 'tono' => 'camino'],
        'cerca' => ['texto' => 'Ya estoy cerca', 'icono' => '📍', 'tono' => 'camino'],
        'llegue' => ['texto' => 'He llegado al punto', 'icono' => '🚩', 'tono' => 'sitio'],
        'listo' => ['texto' => 'Listo, cuando quieras', 'icono' => '⚔️', 'tono' => 'sitio'],
        'esperame' => ['texto' => 'Esperame, ya voy', 'icono' => '🙏', 'tono' => 'camino'],
        'muerto' => ['texto' => 'Me han matado', 'icono' => '💀', 'tono' => 'aviso'],
        'un_momento' => ['texto' => 'Dame un momento', 'icono' => '⏳', 'tono' => 'aviso'],
        'perdido' => ['texto' => 'No encuentro el punto', 'icono' => '🧭', 'tono' => 'aviso'],
        'tardas' => ['texto' => '¿Tardas mucho?', 'icono' => '👀', 'tono' => 'prisa'],
        'vamos' => ['texto' => '¡Vamos!', 'icono' => '🔥', 'tono' => 'prisa'],
    ];

    protected $fillable = [
        'match_id',
        'player_id',
        'code',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public static function esUnCodigo(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::CATALOGO);
    }

    /** @return array{texto: string, icono: string, tono: string}|null */
    public static function definicion(?string $code): ?array
    {
        return self::CATALOGO[$code] ?? null;
    }

    /**
     * El catalogo tal y como lo pinta la botonera.
     *
     * @return array<int, array<string, string>>
     */
    public static function paraLaBotonera(): array
    {
        $botones = [];

        foreach (self::CATALOGO as $code => $definicion) {
            $botones[] = array_merge(['code' => $code], $definicion);
        }

        return $botones;
    }

    public function texto(): string
    {
        // Un codigo que ya no esta en el catalogo -retirado en una version
        // posterior, con avisos suyos todavia en la tabla- no puede pintarse
        // crudo: "camino" no le dice nada a nadie y parece un fallo.
        return self::CATALOGO[$this->code]['texto'] ?? 'Aviso';
    }

    public function icono(): string
    {
        return self::CATALOGO[$this->code]['icono'] ?? '•';
    }

    public function tono(): string
    {
        return self::CATALOGO[$this->code]['tono'] ?? 'aviso';
    }
}
