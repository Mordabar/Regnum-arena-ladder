<?php

namespace App\Providers;

use App\Services\ArenaZoneService;
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
        //
    }
}