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
     * al llegar, 'aviso' cuando algo pasa, 'social' para contestar y
     * despedirse, y 'prisa' para meter presion sin que suene a insulto.
     */
    public const CATALOGO = [
        'voy' => ['texto' => 'Voy en camino', 'icono' => '🏃', 'tono' => 'camino'],
        'cerca' => ['texto' => 'Estoy cerca', 'icono' => '📍', 'tono' => 'camino'],
        'llegue' => ['texto' => 'Estoy en el punto', 'icono' => '🚩', 'tono' => 'sitio'],
        'esperame' => ['texto' => 'Espérame, ya voy', 'icono' => '🙏', 'tono' => 'camino'],
        'un_momento' => ['texto' => 'Dame un momento', 'icono' => '⏳', 'tono' => 'aviso'],
        // Lo que pasa en medio del combate: un tercero que se mete es lo mas
        // comun en un mundo abierto, y decirlo evita que el rival crea que
        // se esta escondiendo.
        'atacan' => ['texto' => 'Me están atacando', 'icono' => '⚔️', 'tono' => 'aviso'],
        'tercero' => ['texto' => 'Me atacó un tercero', 'icono' => '🗡️', 'tono' => 'aviso'],
        'muerto' => ['texto' => 'Me han matado', 'icono' => '💀', 'tono' => 'aviso'],
        // Lo social: contestar sin escribir y despedirse bien.
        'ok' => ['texto' => 'Ok', 'icono' => '👍', 'tono' => 'social'],
        'gg' => ['texto' => 'Bien jugado', 'icono' => '🤝', 'tono' => 'social'],
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
