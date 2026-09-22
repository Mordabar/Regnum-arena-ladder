<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un navegador suscrito a los avisos.
 *
 * Es del navegador y no de la persona: el mismo jugador en el portatil y en
 * el movil son dos filas, y las dos suenan.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
    ];

    protected $casts = [
        'ultimo_ok_at' => 'datetime',
        'fallos' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
