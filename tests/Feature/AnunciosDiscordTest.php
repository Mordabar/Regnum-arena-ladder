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

it('anuncia el arranque de un combate con los reinos, sin zona ni nombres', function () {
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
        // Ni la zona: en mundo abierto invitaria a terceros a meterse.
        ->and(json_encode($anuncios))->not->toContain($match->zone_name)
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

    // 2v2, que esta encendida por defecto: con 1v1 el pulso salia vacio por
    // falta de modalidad y el test pasaba sin mirar el interruptor.
    entraEnCola(guerreroAnuncio('Apagado', 'ignis'), '2v2');
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

it('una party que abre la cola tambien se anuncia, una sola vez', function () {
    $a = guerreroAnuncio('Lider', 'syrtis');
    $b = guerreroAnuncio('Aliado', 'syrtis');
    foreach ([$a, $b] as $p) {
        Queue::create(['player_id' => $p->id, 'queue_type' => 'premade', 'arena_mode' => '2v2', 'status' => 'waiting', 'team_id' => 'equipo-1', 'joined_at' => now(), 'expires_at' => now()->addMinutes(30)]);
    }
    finDePeticion();

    $anuncios = anunciosEnviados();
    expect($anuncios)->toHaveCount(1)
        ->and($anuncios[0]['description'])->toContain('Un grupo')->toContain('Syrtis');
});

it('el mantenimiento lanza el pulso aunque no haya cron', function () {
    Queue::withoutEvents(fn () => entraEnCola(guerreroAnuncio('Tick', 'alsius'), '2v2'));

    app(\App\Services\ArenaMaintenanceService::class)->runTick(false);
    finDePeticion();

    expect(anunciosEnviados())->toHaveCount(1);
});

it('los anuncios del canal salen en español e ingles', function () {
    entraEnCola(guerreroAnuncio('Bilingue', 'ignis'));
    finDePeticion();

    $mensaje = collect(Http::recorded())
        ->map(fn ($par) => $par[0])
        ->first(fn (PeticionHttp $r) => str_contains($r->url(), '/channels/555000000000000001/messages'));
    $embeds = $mensaje['embeds'];

    // Discord junta las tarjetas que comparten URL: la segunda no puede llevar la misma.
    expect($embeds)->toHaveCount(2)
        ->and(collect($embeds)->pluck('url')->filter()->duplicates())->toBeEmpty()
        ->and($embeds[0]['title'])->toContain('🇪🇸')->toContain('Competitivo')->toContain('hay alguien esperando rival')->not->toContain('waiting')
        ->and($embeds[0]['description'])->toContain('es vuestro momento')->not->toContain('your moment')
        ->and($embeds[1]['title'])->toContain('🇬🇧')->toContain('Competitive')->toContain('someone is waiting for an opponent')->not->toContain('esperando')
        ->and($embeds[1]['description'])->toContain('your moment is now')->not->toContain('vuestro');
});

it('el usuario guarda su idioma al elegirlo', function () {
    $user = User::create(['discord_id' => '300000000000000001', 'discord_username' => 'loc', 'name' => 'Loc', 'email' => 'loc@example.com']);

    $this->actingAs($user)->get('/ladder?lang=de');

    expect($user->fresh()->locale)->toBe('de');

    $this->actingAs($user)->get('/ladder');

    expect($user->fresh()->locale)->toBe('de');
});
