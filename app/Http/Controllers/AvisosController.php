<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\AvisosPendientesService;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Las tres puertas de los avisos del navegador: apuntarse, borrarse y
 * preguntar que hay.
 */
class AvisosController extends Controller
{
    /**
     * Apuntar este navegador.
     *
     * La direccion que manda el navegador es la identidad de la suscripcion,
     * asi que se hace `updateOrCreate` sobre ella: volver a entrar en la
     * misma maquina no crea una fila nueva, y si la direccion cambio de dueño
     * -alguien presto el portatil y entro con su cuenta- pasa al nuevo en vez
     * de mandarle los avisos del anterior.
     */
    public function suscribir(Request $request, WebPushService $push): JsonResponse
    {
        if (!$push->configurado()) {
            return response()->json(['ok' => false, 'motivo' => 'Los avisos no estan configurados en el servidor.'], 503);
        }

        $datos = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url'],
            'keys.p256dh' => ['nullable', 'string', 'max:120'],
            'keys.auth' => ['nullable', 'string', 'max:60'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $datos['endpoint']],
            [
                'user_id' => Auth::id(),
                'p256dh' => $datos['keys']['p256dh'] ?? null,
                'auth' => $datos['keys']['auth'] ?? null,
                'fallos' => 0,
            ]
        );

        return response()->json(['ok' => true]);
    }

    /** Borrar este navegador. Al silenciar las alertas o al revocar el permiso. */
    public function desuscribir(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        PushSubscription::query()
            ->where('endpoint', $datos['endpoint'])
            ->where('user_id', Auth::id())
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Mover una suscripcion a su direccion nueva.
     *
     * El servicio de push rota las direcciones por su cuenta. Cuando pasa,
     * quien tiene que avisar es el service worker, y ahi no hay documento: no
     * hay token CSRF que mandar ni sesion garantizada -el navegador lo
     * despierta solo-.
     *
     * Por eso la credencial aqui es la direccion VIEJA. Solo la conoce el
     * navegador que ya estaba suscrito, y lo unico que se puede hacer con
     * ella es mover esa misma fila: no se puede dar de alta un destino nuevo
     * ni cambiarle el dueño. Si la vieja no esta en la tabla, no se hace
     * nada.
     */
    public function resuscribir(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'viejo' => ['required', 'string', 'max:500'],
            'nuevo' => ['required', 'string', 'max:500', 'url'],
            'keys.p256dh' => ['nullable', 'string', 'max:120'],
            'keys.auth' => ['nullable', 'string', 'max:60'],
        ]);

        $suscripcion = PushSubscription::query()->where('endpoint', $datos['viejo'])->first();

        if (!$suscripcion) {
            // Ni se confirma ni se desmiente: quien pregunte por una direccion
            // que no tenemos no averigua nada.
            return response()->json(['ok' => true]);
        }

        // Si la direccion nueva ya estaba guardada -otra fila del mismo
        // navegador-, se quita la duplicada antes de mover: la columna es
        // unica y el guardado fallaria.
        PushSubscription::query()
            ->where('endpoint', $datos['nuevo'])
            ->where('id', '!=', $suscripcion->id)
            ->delete();

        $suscripcion->forceFill([
            'endpoint' => $datos['nuevo'],
            'p256dh' => $datos['keys']['p256dh'] ?? $suscripcion->p256dh,
            'auth' => $datos['keys']['auth'] ?? $suscripcion->auth,
            'fallos' => 0,
        ])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Que hay que contarle al jugador ahora mismo.
     *
     * Lo llama el service worker cuando le llega un toque. Se responde
     * siempre 200 con una lista, aunque este vacia: el worker sabe
     * arreglarselas y un error ahi solo se traduce en un aviso generico.
     */
    public function pendientes(AvisosPendientesService $avisos): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'avisos' => $user ? $avisos->para($user) : [],
        ])->header('Cache-Control', 'no-store');
    }
}
