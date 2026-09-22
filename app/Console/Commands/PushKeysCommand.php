<?php

namespace App\Console\Commands;

use App\Support\VapidKeys;
use Illuminate\Console\Command;

/**
 * Genera el par de claves de los avisos y lo enseña listo para pegar.
 *
 * NO escribe en el `.env` a proposito. El `.env` de produccion se edita por
 * FTP y tiene secretos de verdad dentro; que un comando lo reescriba es una
 * forma facil de perderlos. Se enseña, se copia y se pega.
 */
class PushKeysCommand extends Command
{
    protected $signature = 'arena:push-keys';

    protected $description = 'Genera las claves VAPID para los avisos del navegador';

    public function handle(): int
    {
        if (config('services.webpush.public_key')) {
            $this->warn('Ya hay claves configuradas.');
            $this->line('Si las cambias, TODOS los navegadores suscritos dejan de recibir avisos');
            $this->line('y tienen que volver a dar permiso. Solo sigue si es lo que quieres.');
            $this->newLine();

            if (!$this->confirm('¿Generar un par nuevo de todas formas?', false)) {
                return self::SUCCESS;
            }
        }

        $par = VapidKeys::generar();

        $this->newLine();
        $this->info('Pega estas tres lineas en el .env y limpia la cache de configuracion:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY=' . $par['publica']);
        $this->line('VAPID_PRIVATE_KEY=' . $par['privada']);
        $this->line('VAPID_SUBJECT=mailto:tu-correo@ejemplo.com');
        $this->newLine();
        $this->comment('La privada no se vuelve a enseñar. Guardala antes de cerrar.');

        return self::SUCCESS;
    }
}
