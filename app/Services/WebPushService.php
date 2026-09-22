<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PushSubscription;
use App\Support\Base64Url;
use App\Support\VapidKeys;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Los avisos que llegan con la pestaña cerrada.
 *
 * Por que hace falta esto y no basta con el sondeo: el navegador congela las
 * pestañas que no estan delante. Primero espacia los temporizadores a uno por
 * minuto y a los pocos minutos las para del todo, asi que la pagina no se
 * entera de nada hasta que se vuelve a mirar, que es justo cuando el aviso ya
 * no sirve. Lo unico que atraviesa eso es el push del navegador: lo entrega el
 * sistema operativo, sin la pagina abierta.
 *
 * Envio SIN carga util, a proposito. Un push con contenido hay que cifrarlo
 * (aes128gcm, acuerdo de claves ECDH, HKDF) y eso son varios cientos de lineas
 * de criptografia escrita a mano o una dependencia mas que subir por FTP a un
 * hosting compartido. Un push vacio solo necesita la firma VAPID, que son
 * veinte lineas de openssl. El service worker recibe el toque, le pregunta al
 * sitio que ha pasado y enseña el aviso. Como efecto secundario, lo que se
 * enseña es el estado de AHORA y no el de cuando se mando: si el cruce ya
 * caduco, no sale una notificacion mintiendo.
 */
class WebPushService
{
    /** Doce horas. El maximo que admite la especificacion es 24. */
    private const JWT_VIDA = 43200;

    /** Cuanto guarda el servicio el aviso si el movil esta apagado. */
    private const TTL = 900;

    /** Fallos seguidos antes de dar la suscripcion por muerta. */
    private const FALLOS_PARA_TIRARLA = 5;

    public function configurado(): bool
    {
        return $this->clavePublica() !== '' && (string) config('services.webpush.private_key', '') !== '';
    }

    public function clavePublica(): string
    {
        return (string) config('services.webpush.public_key', '');
    }

    /**
     * Toca a los jugadores de un enfrentamiento.
     *
     * Se hace DESPUES de responder, con `terminating()`: en este hosting la
     * cola es `sync`, asi que cualquier cosa que se mande aqui la paga la
     * peticion que la provoco. Un cruce de 3v3 son seis envios a servicios
     * externos, y sumarle dos segundos a la peticion que crea el
     * enfrentamiento es cambiar un problema por otro.
     *
     * @param  iterable<array{player_id?: int|string}>  $jugadores  el formato de `getAllPlayers()`
     * @param  list<int>  $exceptoPlayerIds  a quien NO avisar: normalmente quien acaba de actuar
     */
    public function avisarAJugadores(iterable $jugadores, array $exceptoPlayerIds = []): void
    {
        if (!$this->configurado()) {
            return;
        }

        $playerIds = collect($jugadores)
            ->map(fn ($j) => (int) (is_array($j) ? ($j['player_id'] ?? 0) : $j))
            ->filter()
            ->reject(fn (int $id) => in_array($id, $exceptoPlayerIds, true))
            ->unique()
            ->values();

        if ($playerIds->isEmpty()) {
            return;
        }

        $userIds = Player::query()->whereIn('id', $playerIds)->pluck('user_id')->filter()->unique()->all();

        if ($userIds === []) {
            return;
        }

        app()->terminating(function () use ($userIds) {
            try {
                $this->avisar($userIds);
            } catch (Throwable $e) {
                // Un aviso que no sale nunca puede tumbar lo que lo provoco.
                Log::warning('No se pudieron mandar los avisos de push', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Toca a todas las suscripciones de estos usuarios.
     *
     * @param  iterable<int>  $userIds
     * @return int  cuantas salieron
     */
    public function avisar(iterable $userIds): int
    {
        $ids = collect($userIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty() || !$this->configurado()) {
            return 0;
        }

        $enviados = 0;

        foreach (PushSubscription::query()->whereIn('user_id', $ids)->get() as $suscripcion) {
            if ($this->enviar($suscripcion)) {
                $enviados++;
            }
        }

        return $enviados;
    }

    /**
     * Un toque a un navegador concreto.
     *
     * Nunca lanza: un aviso que no sale no puede tumbar la accion que lo
     * provoco. Que el push de un jugador falle no puede impedir que el
     * enfrentamiento se cree.
     */
    public function enviar(PushSubscription $suscripcion): bool
    {
        try {
            $respuesta = Http::withHeaders([
                'Authorization' => $this->cabeceraVapid($suscripcion->endpoint),
                'TTL' => (string) self::TTL,
                // "high" es lo que hace que el sistema lo entregue ya en vez
                // de agruparlo con el siguiente que toque. Un cruce dura dos
                // minutos: entregarlo tarde es no entregarlo.
                'Urgency' => 'high',
                'Content-Length' => '0',
            ])->timeout(8)->withBody('', 'application/octet-stream')->post($suscripcion->endpoint);

            // 404 y 410 son la forma en que el servicio dice "este navegador
            // ya no existe": desinstalaron la app, limpiaron los datos del
            // sitio o revocaron el permiso. Insistir no arregla nada.
            if (in_array($respuesta->status(), [404, 410], true)) {
                $suscripcion->delete();

                return false;
            }

            if ($respuesta->successful()) {
                $suscripcion->forceFill(['fallos' => 0, 'ultimo_ok_at' => now()])->save();

                return true;
            }

            $this->anotarFallo($suscripcion, 'HTTP ' . $respuesta->status());

            return false;
        } catch (Throwable $e) {
            $this->anotarFallo($suscripcion, $e->getMessage());

            return false;
        }
    }

    /**
     * La cabecera `Authorization` del esquema VAPID: un JWT firmado con la
     * clave privada y, al lado, la publica para que el servicio de push pueda
     * comprobar la firma.
     */
    private function cabeceraVapid(string $endpoint): string
    {
        $partes = parse_url($endpoint);
        $audiencia = ($partes['scheme'] ?? 'https') . '://' . ($partes['host'] ?? '');

        $cabecera = ['typ' => 'JWT', 'alg' => 'ES256'];
        $cuerpo = [
            // El destinatario es el SERVICIO de push, no el navegador: un
            // token firmado para Google no vale para Mozilla, y ahi esta la
            // mitad de los 401 que se ven en esto.
            'aud' => $audiencia,
            'exp' => time() + self::JWT_VIDA,
            'sub' => $this->contacto(),
        ];

        $sinFirmar = Base64Url::encode(json_encode($cabecera, JSON_UNESCAPED_SLASHES))
            . '.' . Base64Url::encode(json_encode($cuerpo, JSON_UNESCAPED_SLASHES));

        $pem = VapidKeys::pem(
            (string) config('services.webpush.private_key'),
            $this->clavePublica()
        );

        openssl_sign($sinFirmar, $der, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256);

        $jwt = $sinFirmar . '.' . Base64Url::encode(VapidKeys::firmaEnCrudo($der));

        return 'vapid t=' . $jwt . ', k=' . $this->clavePublica();
    }

    /**
     * A quien escribir si los avisos de este sitio dan problemas. Es
     * obligatorio y tiene que ser un `mailto:` o una direccion web.
     */
    private function contacto(): string
    {
        $configurado = trim((string) config('services.webpush.subject', ''));

        if ($configurado !== '') {
            return $configurado;
        }

        return rtrim((string) config('app.url', 'https://regnumarenaladder.top'), '/');
    }

    private function anotarFallo(PushSubscription $suscripcion, string $motivo): void
    {
        $fallos = (int) $suscripcion->fallos + 1;

        if ($fallos >= self::FALLOS_PARA_TIRARLA) {
            $suscripcion->delete();
            Log::info('Suscripcion de push retirada tras fallar seguido', ['motivo' => $motivo]);

            return;
        }

        $suscripcion->forceFill(['fallos' => $fallos])->save();
    }
}
