<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\AvisosPendientesService;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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
            'endpoint' => ['required', 'string', 'max:500', 'url', $this->servicioDePush($push)],
            'keys.p256dh' => ['nullable', 'string', 'max:120'],
            'keys.auth' => ['nullable', 'string', 'max:60'],
            'anterior' => ['nullable', 'string', 'max:500'],
        ]);

        // La direccion que este mismo navegador tenia antes, si cambio. Solo
        // se borra si es de este usuario: no se puede tirar la de otro
        // adivinando su direccion.
        if (!empty($datos['anterior']) && $datos['anterior'] !== $datos['endpoint']) {
            PushSubscription::query()
                ->where('endpoint', $datos['anterior'])
                ->where('user_id', Auth::id())
                ->delete();
        }

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
    public function resuscribir(Request $request, WebPushService $push): JsonResponse
    {
        $datos = $request->validate([
            'viejo' => ['required', 'string', 'max:500'],
            'nuevo' => ['required', 'string', 'max:500', 'url', $this->servicioDePush($push)],
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
     * Mandarle a este navegador un push DE VERDAD, ahora.
     *
     * Es la unica prueba que cubre el camino entero: servidor → servicio de
     * push (Google, Mozilla, Apple) → dispositivo → service worker → pantalla.
     * Un aviso de prueba pintado desde la propia pagina solo demostraba que el
     * sistema enseña notificaciones; este demuestra que llegan con la pagina
     * cerrada, que es lo que se prometio.
     *
     * Se responde con lo que dijo el servicio de push. Si lo acepta y aun asi
     * no aparece nada en el dispositivo, el fallo esta en el ultimo tramo y
     * la pagina lo sabe porque el worker no le confirma la entrega.
     */
    public function probar(Request $request, WebPushService $push, AvisosPendientesService $avisos): JsonResponse
    {
        $datos = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $suscripcion = PushSubscription::query()
            ->where('endpoint', $datos['endpoint'])
            ->where('user_id', Auth::id())
            ->first();

        if (!$suscripcion) {
            return response()->json(['ok' => false, 'motivo' => 'sin-suscripcion'], 404);
        }

        // Lo que el worker va a encontrar cuando pregunte que ha pasado.
        $avisos->marcarPrueba((int) Auth::id());

        $resultado = $push->enviarConDetalle($suscripcion);

        if (!$resultado['ok']) {
            $this->anotarFallo($request, 'prueba-servidor', 'HTTP ' . ($resultado['estado'] ?? '?') . ' ' . ($resultado['cuerpo'] ?? ''));
        }

        // Al navegador, solo el codigo. El cuerpo de lo que respondio el
        // servicio de push se queda en el registro del servidor: devolverlo
        // era regalar la respuesta de cualquier sitio al que se consiguiera
        // apuntar la peticion.
        return response()->json([
            'ok' => $resultado['ok'],
            'estado' => $resultado['estado'],
            'servicio' => $resultado['servicio'],
        ], $resultado['ok'] ? 200 : 502);
    }

    /**
     * Lo que fallo en el navegador de alguien.
     *
     * Sin esto, "no me llegan los avisos" solo se podia diagnosticar con la
     * consola del navegador abierta, que es lo ultimo que va a hacer un
     * jugador. Se guardan los ultimos en la cache y `arena:push-check` los
     * enseña con su motivo real.
     *
     * Sin CSRF a proposito: uno de los fallos posibles es justamente el token
     * caducado, y la baliza tiene que llegar igual. No hace nada mas que
     * anotar, esta limitada por minuto y cada campo va recortado.
     */
    public function fallo(Request $request): JsonResponse
    {
        $datos = json_decode((string) $request->getContent(), true);

        if (!is_array($datos)) {
            return response()->json(['ok' => false], 422);
        }

        $this->anotarFallo(
            $request,
            (string) ($datos['causa'] ?? 'desconocida'),
            (string) ($datos['detalle'] ?? ''),
            [
                'permiso' => (string) ($datos['permiso'] ?? ''),
                'standalone' => !empty($datos['standalone']),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public const CLAVE_FALLOS = 'arena:avisos:fallos';

    private function anotarFallo(Request $request, string $causa, string $detalle, array $extra = []): void
    {
        // Lo manda un navegador, o cualquiera. Sin caracteres de control: esto
        // se imprime luego en una terminal, y una secuencia de escape
        // colada aqui se ejecutaria en la de quien lance `arena:push-check`.
        $limpio = fn (string $v): string => (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v);
        $causa = $limpio($causa);
        $detalle = $limpio($detalle);
        $extra = array_map(fn ($v) => is_string($v) ? $limpio($v) : $v, $extra);

        $lista = Cache::get(self::CLAVE_FALLOS, []);

        array_unshift($lista, array_merge([
            'en' => now()->toDateTimeString(),
            'usuario' => Auth::id(),
            'causa' => mb_substr($causa, 0, 40),
            'detalle' => mb_substr($detalle, 0, 300),
            'navegador' => mb_substr($limpio((string) $request->userAgent()), 0, 160),
        ], array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 40) : $v, $extra)));

        Cache::put(self::CLAVE_FALLOS, array_slice($lista, 0, 30), now()->addDays(7));
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

    /** Regla: la direccion tiene que ser de un servicio de push de verdad. */
    private function servicioDePush(WebPushService $push): \Closure
    {
        return function (string $atributo, $valor, \Closure $falla) use ($push) {
            if (!is_string($valor) || !$push->servicioPermitido($valor)) {
                $falla('Esa direccion no es de un servicio de avisos conocido.');
            }
        };
    }
}
