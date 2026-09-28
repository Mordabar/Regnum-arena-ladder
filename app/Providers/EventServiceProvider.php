<?php

namespace App\Providers;

use App\Models\ArenaMatch;
use App\Models\Queue;
use App\Services\ArenaZoneService;
use App\Services\Discord\ActivityAnnouncer;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SocialiteWasCalled::class => [
            DiscordExtendSocialite::class,
        ],
    ];

    public function register(): void
    {
        parent::register();

        // Uno por peticion. El servicio guarda las zonas en memoria despues de
        // leerlas, y en una misma pagina lo piden el mapa, el emparejador y el
        // enlace con version del script: con una instancia por sitio esa
        // memoria no sirve de nada y son tres lecturas de la tabla.
        $this->app->singleton(ArenaZoneService::class);
    }

    public function boot(): void
    {
        // Anuncios de actividad en el canal de Discord. Van colgados de los
        // modelos y no de los controladores: da igual por donde entre alguien
        // en cola (lobby, grupo, panel) o por donde arranque un combate.
        Queue::created(function (Queue $queue) {
            app(ActivityAnnouncer::class)->queueJoined($queue);
        });

        ArenaMatch::updated(function (ArenaMatch $match) {
            if ($match->wasChanged('status') && $match->status === 'in_progress') {
                app(ActivityAnnouncer::class)->matchStarted($match);
            }
        });
    }
}