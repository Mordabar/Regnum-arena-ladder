<?php

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Models\User;
use App\Services\Discord\ActivityAnnouncer;
use App\Services\TestingLabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Los anuncios de actividad del canal de Discord: ambiente, nunca nombres, y
 * sin hacer ruido.
 */

beforeEach(function () {
    config([
        'services.discord.bot_token' => 'token-de-prueba',
        'services.discord.announcements.channel_id' => '555000000000000001',
    ]);
    Http::fake(['discord.com/*' => Http::response(['id' => 'ok'])]);
});

function guerreroAnuncio(string $nombre, string $reino, ?string $discordId = null): Player
{
    $user = User::create([
        'discord_id' => $discordId ?? (string) random_int(100000000000000000, 199999999999999999),
        'discord_username' => strtolower($nombre), 'name' => $nombre, 'email' => strtolower($nombre) . '@example.com',
    ]);

    return Player::create(['user_id' => $user->id, 'character_name' => $nombre, 'subclass' => 'knight', 'realm' => $reino, 'pl_points' => 0, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true]);
}

function entraEnCola(Player $p, string $modo = '1v1'): Queue
{
    return Queue::create(['player_id' => $p->id, 'queue_type' => 'random', 'arena_mode' => $modo, 'status' => 'waiting', 'joined_at' => now(), 'expires_at' => now()->addMinutes(30)]);
}

/**
 * El final de una peticion: lo aplazado se ejecuta una vez y se descarta.
 * app()->terminate() a secas vuelve a ejecutar lo de las anteriores, cosa que
 * en produccion no pasa (cada peticion tiene su propia aplicacion).
 */
function finDePeticion(): void
{
    app()->terminate();

    $propiedad = new ReflectionProperty(app(), 'terminatingCallbacks');
    $propiedad->setValue(app(), []);
}

function anunciosEnviados(): array
{
    return collect(Http::recorded())
        ->map(fn ($par) => $par[0])
        ->filter(fn (PeticionHttp $r) => str_contains($r->url(), '/channels/555000000000000001/messages'))
        ->map(fn (PeticionHttp $r) => $r['embeds'][0])
        ->values()
        ->all();
}

it('anuncia cuando alguien entra en una cola vacia, sin decir quien', function () {
    entraEnCola(guerreroAnuncio('Secretonombre', 'ignis'));
    finDePeticion();

    $anuncios = anunciosEnviados();
    expect($anuncios)->toHaveCount(1)
        ->and($anuncios[0]['title'])->toContain('1v1')
        ->and($anuncios[0]['description'])->toContain('Ignis')
        ->and(json_encode($anuncios))->not->toContain('Secretonombre');
});

it('no repite si la cola ya tenia gente', function () {
    entraEnCola(guerreroAnuncio('Uno', 'ignis'));
    finDePeticion();
    entraEnCola(guerreroAnuncio('Dos', 'alsius'));
    finDePeticion();

    expect(anunciosEnviados())->toHaveCount(1);
});

it('no anuncia una cola que ya salio emparejada en la misma peticion', function () {
    $cola = entraEnCola(guerreroAnuncio('Rapido', 'ignis'));
    $cola->update(['status' => 'matched']);
    finDePeticion();

    expect(anunciosEnviados())->toBe([]);
});

it('los bots del laboratorio no anuncian nada', function () {
    $prefijo = (new ReflectionClassConstant(TestingLabService::class, 'LAB_DISCORD_PREFIX'))->getValue();
    entraEnCola(guerreroAnuncio('Bot', 'ignis', $prefijo . 'x1'));
    finDePeticion();

    expect(anunciosEnviados())->toBe([]);
});

it('anuncia el arranque de un combate con reinos y zona, sin nombres', function () {
    $a = guerreroAnuncio('Nombreoculto', 'ignis');
    $b = guerreroAnuncio('Otrooculto', 'alsius');
    $fila = fn (Player $p) => ['player_id' => $p->id, 'character_name' => $p->character_name, 'subclass' => 'knight', 'realm' => $p->realm, 'discord_id' => $p->user->discord_id, 'conjurer_role' => null];
    $match = ArenaMatch::create([
        'match_code' => 'ARENA-ANUN', 'report_token' => 'ANUNCIO001', 'queue_mode' => 'random', 'arena_mode' => '1v1',
        'team_a_realm' => 'ignis', 'team_b_realm' => 'alsius', 'team_a' => [$fila($a)], 'team_b' => [$fila($b)],
        'zone' => 'emerald_pass', 'status' => 'pending_acceptance', 'estimated_mmr_avg' => 1000, 'expires_at' => now()->addMinutes(3),
    ]);

    $match->update(['status' => 'in_progress', 'started_at' => now()]);
    finDePeticion();

    $anuncios = anunciosEnviados();
    expect($anuncios)->toHaveCount(1)
        ->and($anuncios[0]['description'])->toContain('Ignis')->toContain('Alsius')
        ->and(json_encode($anuncios))->not->toContain('Nombreoculto')->not->toContain('Otrooculto');
});

it('el pulso del cron solo sale con actividad y como mucho una vez por periodo', function () {
    $anunciador = app(ActivityAnnouncer::class);

    $anunciador->pulse();
    expect(anunciosEnviados())->toBe([]);

    Queue::withoutEvents(fn () => entraEnCola(guerreroAnuncio('Pulso', 'syrtis'), '2v2'));

    $anunciador->pulse();
    $anunciador->pulse();
    finDePeticion();

    $anuncios = anunciosEnviados();
    expect($anuncios)->toHaveCount(1)
        ->and($anuncios[0]['description'])->toContain('2v2: 1');
});

it('el interruptor del panel los apaga', function () {
    AppSetting::setValue(ActivityAnnouncer::SETTING_ENABLED, '0', 'runtime', 'boolean', false);

    entraEnCola(guerreroAnuncio('Apagado', 'ignis'));
    app(ActivityAnnouncer::class)->pulse();
    finDePeticion();

    expect(anunciosEnviados())->toBe([]);
});

it('sin canal configurado no hace nada', function () {
    config(['services.discord.announcements.channel_id' => '']);

    entraEnCola(guerreroAnuncio('Sincanal', 'ignis'));
    finDePeticion();

    Http::assertNothingSent();
});

it('arena:anuncios --probar manda un mensaje al canal', function () {
    $this->artisan('arena:anuncios --probar')->assertSuccessful();
    finDePeticion();

    expect(anunciosEnviados())->toHaveCount(1);
});
