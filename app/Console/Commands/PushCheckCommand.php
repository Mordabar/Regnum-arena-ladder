<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use App\Support\VapidKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

    /**
     * La version de public/sw.js que va con este codigo. Se sube a la vez que
     * la constante VERSION del worker: asi se sabe si por FTP quedo el viejo.
     */
    public const VERSION_WORKER = 'arena-avisos-4';

    public function handle(WebPushService $push): int
    {
        $this->newLine();
        $this->info('Avisos del navegador — revision');
        $this->newLine();

        $todoBien = true;

        $todoBien = $this->revisarClaves() && $todoBien;
        $todoBien = $this->revisarTabla() && $todoBien;
        $todoBien = $this->revisarWorker() && $todoBien;
        $todoBien = $this->revisarRutas() && $todoBien;
        $todoBien = $this->revisarManifiesto() && $todoBien;
        $todoBien = $this->revisarSuscripciones() && $todoBien;

        $this->revisarDiscord();
        $this->enseñarFallosDeNavegadores();

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

        // Subido, pero ¿el de ahora? Con FTP es facil dejar el viejo: sin la
        // linea del acuse, la prueba de "Activar" nunca confirma.
        if (!str_contains((string) file_get_contents($ruta), "const VERSION = '" . self::VERSION_WORKER . "'")) {
            $this->falla('sw.js', 'es una version vieja: vuelve a subir public/sw.js');

            return false;
        }

        $this->bien('sw.js', 'en ' . $ruta);
        $this->line('   Compruebalo en el navegador: ' . rtrim((string) config('app.url'), '/') . '/sw.js');

        return true;
    }

    /**
     * Las rutas de los avisos, tal y como las ve la aplicacion AHORA.
     *
     * Si en el servidor quedo un `route:cache` de antes de subir esto, las
     * rutas nuevas no existen aunque el fichero si: el boton da 404 al
     * activar y no hay forma de verlo desde fuera.
     */
    private function revisarRutas(): bool
    {
        $faltan = array_values(array_filter(
            ['avisos.suscribir', 'avisos.desuscribir', 'avisos.pendientes', 'avisos.probar', 'avisos.fallo', 'avisos.resuscribir'],
            fn (string $nombre) => !Route::has($nombre)
        ));

        if ($faltan !== []) {
            $this->falla('Rutas de avisos', 'faltan ' . implode(', ', $faltan));
            $this->line('   Casi siempre es una cache de rutas vieja:');
            $this->line('   php artisan route:clear && php artisan config:clear && php artisan view:clear');

            return false;
        }

        $this->bien('Rutas de avisos', 'las seis estan');

        if (app()->routesAreCached()) {
            $this->aviso('Cache de rutas', 'activa: tras cada subida, php artisan route:clear');
        }

        return true;
    }

    /** El manifiesto y los iconos: sin ellos, en iPhone no hay "añadir a inicio" con avisos. */
    private function revisarManifiesto(): bool
    {
        $faltan = array_values(array_filter(
            ['manifest.webmanifest', 'images/icono-192.png', 'images/icono-512.png'],
            fn (string $fichero) => !is_file(public_path($fichero))
        ));

        if ($faltan !== []) {
            $this->aviso('Manifiesto e iconos', 'faltan ' . implode(', ', $faltan) . ' (solo afecta a iPhone)');

            return true;
        }

        $this->bien('Manifiesto e iconos', 'en su sitio');

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

    /**
     * El bot de Discord: el unico canal que llega con TODO cerrado.
     *
     * El push del navegador necesita que el navegador exista, aunque sea en
     * segundo plano. El mensaje directo de Discord llega al movil aunque no
     * haya ningun navegador abierto, y es lo que "antes llegaba" para quien
     * lo tenia. Si el token se cambio y no se actualizo el .env, esos
     * mensajes dejan de salir en silencio: aqui se ve.
     */
    private function revisarDiscord(): void
    {
        $token = (string) config('services.discord.bot_token', '');

        if ($token === '') {
            $this->aviso('Bot de Discord', 'sin token: no se mandan mensajes directos');

            return;
        }

        try {
            $r = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                ->timeout(8)
                ->get('https://discord.com/api/v10/users/@me');

            if ($r->successful()) {
                $this->bien('Bot de Discord', 'conectado como ' . ($r->json('username') ?? '?'));
            } elseif ($r->status() === 401) {
                $this->falla('Bot de Discord', 'token rechazado (401)');
                $this->line('   Si cambiaste el token del bot, pon el nuevo en DISCORD_BOT_TOKEN y');
                $this->line('   haz config:clear. Sin el, los mensajes directos de cruce no salen.');
            } else {
                $this->aviso('Bot de Discord', 'respondio HTTP ' . $r->status());
            }
        } catch (Throwable $e) {
            $this->aviso('Bot de Discord', 'no se pudo comprobar: ' . mb_substr($e->getMessage(), 0, 80));
        }
    }

    /**
     * Lo que ha fallado en los navegadores de los jugadores.
     *
     * Cada vez que alguien intenta activar los avisos y no puede, su
     * navegador lo cuenta aqui con el motivo real. Es lo que permite
     * diagnosticar un "a mi no me llega" sin pedirle a nadie que abra la
     * consola.
     */
    private function enseñarFallosDeNavegadores(): void
    {
        $fallos = \Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\AvisosController::CLAVE_FALLOS, []);

        $this->newLine();

        if ($fallos === []) {
            $this->bien('Fallos en navegadores', 'ninguno reciente');

            return;
        }

        $this->aviso('Fallos en navegadores', count($fallos) . ' recientes (el mas nuevo arriba):');

        foreach (array_slice($fallos, 0, 8) as $f) {
            $quien = $f['usuario'] ? 'usuario ' . $f['usuario'] : 'sin sesion';
            $this->line(sprintf('   %s  %-22s %s', $f['en'] ?? '', $f['causa'] ?? '', $quien));

            if (!empty($f['detalle'])) {
                $this->line('      ' . $f['detalle']);
            }

            if (!empty($f['navegador'])) {
                $this->line('      <fg=gray>' . mb_substr($f['navegador'], 0, 110) . '</>');
            }
        }
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

        // Que el aviso que llegue diga "Avisos activados" y no un "hay
        // novedades" que no se sabe de donde sale.
        app(\App\Services\AvisosPendientesService::class)->marcarPrueba($userId);

        foreach ($suscripciones as $suscripcion) {
            // Con la respuesta cruda del servicio de push: es lo unico que
            // distingue "lo rechaza" de "lo acepta y es el dispositivo el que
            // no lo enseña", y son dos problemas completamente distintos.
            $r = $push->enviarConDetalle($suscripcion);
            $servicio = (string) ($r['servicio'] ?? 'servicio');

            if ($r['ok']) {
                $this->bien($servicio, 'aceptado (HTTP ' . $r['estado'] . ')');
                $salieron++;

                continue;
            }

            if ($r['estado'] === null) {
                $this->falla($servicio, 'no se pudo conectar: ' . $r['cuerpo']);

                continue;
            }

            $this->falla($servicio, 'HTTP ' . $r['estado']);

            if (!empty($r['cuerpo'])) {
                $this->line('   ' . $r['cuerpo']);
            }

            $this->pistaDelFallo((int) $r['estado']);
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

    private function aviso(string $que, string $detalle): void
    {
        $this->line('  <fg=yellow>AVISO</> ' . str_pad($que, 26) . $detalle);
    }

    private function falla(string $que, string $detalle): void
    {
        $this->line('  <fg=red>FALLA</> ' . str_pad($que, 26) . $detalle);
    }
}
