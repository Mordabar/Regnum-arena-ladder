<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use App\Support\VapidKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Por que los avisos no llegan.
 *
 * Los avisos del navegador fallan en silencio por diseño: el navegador no
 * cuenta por que no se suscribio, y el servidor no sabe si al otro lado hubo
 * alguien. Con cuatro piezas en juego -claves, tabla, fichero del worker y
 * permiso- "no me llega nada" puede ser cualquiera de ellas, y sin esto la
 * unica via es ir probando a ciegas.
 *
 * Con `--user=` ademas manda un aviso de verdad y enseña lo que responde el
 * servicio de push, que es la respuesta definitiva: o sale, o dice por que no.
 */
class PushCheckCommand extends Command
{
    protected $signature = 'arena:push-check {--user= : Manda un aviso de prueba a este usuario}';

    protected $description = 'Revisa por que no llegan los avisos del navegador';

    public function handle(WebPushService $push): int
    {
        $this->newLine();
        $this->info('Avisos del navegador — revision');
        $this->newLine();

        $todoBien = true;

        $todoBien = $this->revisarClaves() && $todoBien;
        $todoBien = $this->revisarTabla() && $todoBien;
        $todoBien = $this->revisarWorker() && $todoBien;
        $todoBien = $this->revisarSuscripciones() && $todoBien;

        $this->newLine();

        if ($this->option('user')) {
            return $this->mandarPrueba($push, (int) $this->option('user'));
        }

        if ($todoBien) {
            $this->info('Todo en su sitio.');
            $this->line('Para probar de verdad:  php artisan arena:push-check --user=<id>');
            $this->line('El id sale de:          select id, discord_username from users;');
        } else {
            $this->warn('Arregla lo marcado con FALLA y vuelve a pasar esto.');
        }

        $this->newLine();

        return $todoBien ? self::SUCCESS : self::FAILURE;
    }

    private function revisarClaves(): bool
    {
        $publica = (string) config('services.webpush.public_key', '');
        $privada = (string) config('services.webpush.private_key', '');

        if ($publica === '' || $privada === '') {
            $this->falla('Claves VAPID', 'no estan en el .env');
            $this->line('   Generalas con `php artisan arena:push-keys` y pega las tres lineas.');
            $this->line('   Despues: php artisan config:clear');

            return false;
        }

        // Que esten no basta: si la privada y la publica son de generaciones
        // distintas, el servicio de push rechaza cada envio con un 401 y nadie
        // recibe nada. Y openssl no lo detecta solo, asi que se comprueba
        // firmando y verificando.
        try {
            if (!VapidKeys::parCoincide($privada, $publica)) {
                $this->falla('Claves VAPID', 'estan, pero no son un par valido');
                $this->line('   La publica y la privada no se corresponden. Pasa cuando se pega una');
                $this->line('   linea de una generacion y la otra de otra. Genera un par NUEVO con');
                $this->line('   `php artisan arena:push-keys` y pega LAS DOS a la vez.');

                return false;
            }
        } catch (Throwable $e) {
            $this->falla('Claves VAPID', 'no se pueden leer: ' . $e->getMessage());

            return false;
        }

        $this->bien('Claves VAPID', 'correctas (' . substr($publica, 0, 12) . '…)');

        return true;
    }

    private function revisarTabla(): bool
    {
        if (!Schema::hasTable('push_subscriptions')) {
            $this->falla('Tabla push_subscriptions', 'no existe');
            $this->line('   La migracion no ha corrido. Comprueba que el fichero esta:');
            $this->line('   ls database/migrations/ | grep push');
            $this->line('   y luego: php artisan migrate --force');

            return false;
        }

        $this->bien('Tabla push_subscriptions', 'existe');

        return true;
    }

    private function revisarWorker(): bool
    {
        $ruta = public_path('sw.js');

        if (!is_file($ruta)) {
            $this->falla('sw.js', 'no esta en ' . $ruta);
            $this->line('   Sin el no hay avisos: es lo que el sistema despierta con la pagina');
            $this->line('   cerrada. Tiene que quedar EN LA RAIZ publica, la misma carpeta que');
            $this->line('   index.php, porque un service worker solo controla su carpeta hacia');
            $this->line('   abajo. En una subcarpeta no sirve de nada.');

            return false;
        }

        $this->bien('sw.js', 'en ' . $ruta);
        $this->line('   Compruebalo en el navegador: ' . rtrim((string) config('app.url'), '/') . '/sw.js');

        return true;
    }

    private function revisarSuscripciones(): bool
    {
        if (!Schema::hasTable('push_subscriptions')) {
            return false;
        }

        $total = PushSubscription::count();

        if ($total === 0) {
            $this->falla('Navegadores suscritos', 'ninguno');
            $this->line('   El servidor esta listo pero ningun navegador se ha apuntado. En el sitio:');
            $this->line('   dale al interruptor de alertas y acepta el permiso que pide el navegador.');
            $this->line('   Hace falta HTTPS y, en movil, que la pagina no este en incognito.');

            return false;
        }

        $this->bien('Navegadores suscritos', (string) $total);

        $problematicas = PushSubscription::where('fallos', '>', 0)->count();

        if ($problematicas > 0) {
            $this->line('   ' . $problematicas . ' con fallos acumulados: el servicio de push los rechaza.');
        }

        return true;
    }

    private function mandarPrueba(WebPushService $push, int $userId): int
    {
        $user = User::find($userId);

        if (!$user) {
            $this->error('No hay ningun usuario con id ' . $userId . '.');

            return self::FAILURE;
        }

        $suscripciones = PushSubscription::where('user_id', $userId)->get();

        if ($suscripciones->isEmpty()) {
            $this->error('Ese usuario no tiene ningun navegador suscrito.');
            $this->line('Que entre al sitio y active las alertas.');

            return self::FAILURE;
        }

        $this->info('Mandando un aviso a ' . $suscripciones->count() . ' navegador(es) de ' . ($user->discord_username ?: $user->name) . '…');
        $this->newLine();

        $salieron = 0;

        foreach ($suscripciones as $suscripcion) {
            // Se manda a pelo, sin pasar por el servicio, para poder enseñar la
            // respuesta cruda: es lo unico que distingue "el servicio lo
            // rechaza" de "el servicio lo acepta y es el navegador el que no lo
            // enseña", y son dos problemas completamente distintos.
            $servicio = parse_url($suscripcion->endpoint, PHP_URL_HOST);

            try {
                $metodo = new \ReflectionMethod($push, 'cabeceraVapid');
                $metodo->setAccessible(true);

                $respuesta = Http::withHeaders([
                    'Authorization' => $metodo->invoke($push, $suscripcion->endpoint),
                    'TTL' => '60',
                    'Urgency' => 'high',
                    'Content-Length' => '0',
                ])->timeout(10)->withBody('', 'application/octet-stream')->post($suscripcion->endpoint);

                if ($respuesta->successful()) {
                    $this->bien($servicio, 'aceptado (HTTP ' . $respuesta->status() . ')');
                    $salieron++;
                } else {
                    $this->falla($servicio, 'HTTP ' . $respuesta->status());
                    $cuerpo = trim($respuesta->body());

                    if ($cuerpo !== '') {
                        $this->line('   ' . mb_substr($cuerpo, 0, 200));
                    }

                    $this->pistaDelFallo($respuesta->status());
                }
            } catch (Throwable $e) {
                $this->falla($servicio, 'no se pudo conectar: ' . $e->getMessage());
            }
        }

        $this->newLine();

        if ($salieron === 0) {
            $this->warn('No salio ninguno. Mira los codigos de arriba.');

            return self::FAILURE;
        }

        $this->info($salieron . ' aviso(s) entregados al servicio de push.');
        $this->line('Si aun asi no aparece en pantalla, el problema ya no es del servidor:');
        $this->line('  - el sistema operativo puede tener las notificaciones del navegador en silencio');
        $this->line('  - en Windows, el modo concentracion las oculta');
        $this->line('  - el navegador tiene que estar abierto (aunque sea minimizado)');

        return self::SUCCESS;
    }

    private function pistaDelFallo(int $estado): void
    {
        match (true) {
            $estado === 401 || $estado === 403 => $this->line('   La firma no le vale. Casi siempre es que la clave publica del .env no es'
                . PHP_EOL . '   la misma con la que ese navegador se suscribio: si cambiaste las claves,'
                . PHP_EOL . '   hay que vaciar push_subscriptions y que todos vuelvan a activarlas.'),
            $estado === 404 || $estado === 410 => $this->line('   Ese navegador ya no existe para el servicio. Se borra solo en el proximo envio.'),
            $estado === 413 => $this->line('   El aviso es demasiado grande, cosa rara aqui porque va vacio.'),
            $estado === 429 => $this->line('   Demasiados envios seguidos. Espera un poco.'),
            default => null,
        };
    }

    private function bien(string $que, string $detalle): void
    {
        $this->line('  <fg=green>OK</>    ' . str_pad($que, 26) . $detalle);
    }

    private function falla(string $que, string $detalle): void
    {
        $this->line('  <fg=red>FALLA</> ' . str_pad($que, 26) . $detalle);
    }
}
