<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Discord\ActivityAnnouncer;
use App\Services\DiscordBotService;
use Illuminate\Console\Command;

/**
 * Para conectar los anuncios de actividad con el servidor de Discord.
 *
 * Sin opciones dice que falta. Con --probar manda un mensaje al canal para
 * comprobar que el bot llega y tiene permiso de escribir.
 */
class DiscordAnunciosCommand extends Command
{
    protected $signature = 'arena:anuncios {--probar : Manda un mensaje de prueba al canal}';

    protected $description = 'Revisa la conexion de los anuncios de actividad con el canal de Discord.';

    public function handle(ActivityAnnouncer $anuncios, DiscordBotService $discord): int
    {
        $bot = $discord->isConfigured();
        $canal = $anuncios->channelId();
        $activos = (bool) AppSetting::getValue(ActivityAnnouncer::SETTING_ENABLED, true);

        $this->line(($bot ? '<info>OK</info>   ' : '<error>FALTA</error> ') . 'Bot de Discord (DISCORD_BOT_TOKEN)');
        $this->line(($canal !== '' ? '<info>OK</info>   ' : '<error>FALTA</error> ') . 'Canal de anuncios (DISCORD_ANNOUNCEMENTS_CHANNEL_ID)' . ($canal !== '' ? ": {$canal}" : ''));
        $this->line(($activos ? '<info>OK</info>   ' : '<comment>APAGADO</comment> ') . 'Interruptor del panel (Configuracion → Discord)');

        if (!$this->option('probar')) {
            $this->newLine();
            $this->comment('Para comprobar que el bot escribe en el canal: php artisan arena:anuncios --probar');

            return self::SUCCESS;
        }

        if (!$anuncios->test()) {
            $this->error('No se puede probar: falta el bot o el canal.');

            return self::FAILURE;
        }

        $this->info('Mensaje de prueba enviado. Si no aparece en el canal, revisa que el bot tenga permiso para ver el canal y enviar mensajes.');

        return self::SUCCESS;
    }
}
