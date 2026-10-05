<?php

use App\Models\ArenaMatch;
use App\Services\DiscordBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
 * Los mensajes de Discord no pueden hacer esperar al jugador: salen despues de
 * guardar y de responder, como el push. Antes se mandaban dentro de la
 * peticion que creaba el cruce (hasta doce llamadas en un 3v3).
 */

function cruceConDiscord(): ArenaMatch
{
    $fila = fn (int $i, string $reino) => [
        'player_id' => $i, 'character_name' => 'Jugador ' . $i, 'subclass' => 'knight',
        'realm' => $reino, 'discord_id' => (string) (100000000000000000 + $i), 'conjurer_role' => null,
    ];

    return ArenaMatch::create([
        'match_code' => 'ARENA-DISC', 'report_token' => 'DISCORD001', 'queue_mode' => 'random', 'arena_mode' => '2v2',
        'team_a_realm' => 'ignis', 'team_b_realm' => 'alsius',
        'team_a' => [$fila(1, 'ignis'), $fila(2, 'ignis')], 'team_b' => [$fila(3, 'alsius'), $fila(4, 'alsius')],
        'zone' => 'emerald_pass', 'status' => 'pending_acceptance', 'estimated_mmr_avg' => 1000,
        'expires_at' => now()->addMinutes(3),
    ]);
}

beforeEach(function () {
    config(['services.discord.bot_token' => 'token-de-prueba']);
    Http::fake([
        'discord.com/api/v10/users/@me/channels' => fn ($r) => Http::response(['id' => 'dm-' . $r['recipient_id']]),
        'discord.com/api/v10/channels/*' => Http::response(['id' => 'mensaje']),
    ]);
});

it('no llama a discord mientras se atiende la peticion, sino al terminar', function () {
    $match = cruceConDiscord();

    app(DiscordBotService::class)->notifyMatchFound($match);

    Http::assertNothingSent();

    app()->terminate();

    // Cuatro jugadores: abrir el DM y escribir en cada uno.
    Http::assertSentCount(8);
});

it('dentro de una transaccion espera a que se guarde', function () {
    $match = cruceConDiscord();

    DB::transaction(function () use ($match) {
        app(DiscordBotService::class)->notifyMatchFound($match);
        app()->terminate();
        Http::assertNothingSent();
    });

    app()->terminate();
    Http::assertSentCount(8);
});

it('el canal de dm se reutiliza: el segundo aviso cuesta la mitad', function () {
    $match = cruceConDiscord();
    $discord = app(DiscordBotService::class);

    // Una sola peticion (un solo terminate): primero el cruce, luego la
    // aceptacion. La segunda tanda ya encuentra los canales abiertos.
    $discord->notifyMatchFound($match);
    $discord->notifyMatchAccepted($match->fresh());
    app()->terminate();

    // 4 DMs abiertos + 4 mensajes del cruce + 4 de la aceptacion (sin abrir
    // otra vez los DMs).
    Http::assertSentCount(12);
});

it('si discord falla no rompe nada', function () {
    Http::fake(['discord.com/*' => Http::response('caido', 503)]);
    $match = cruceConDiscord();

    app(DiscordBotService::class)->notifyMatchFound($match);
    app()->terminate();

    expect(true)->toBeTrue();
});

it('sin bot configurado no programa nada', function () {
    config(['services.discord.bot_token' => '']);

    app()->forgetInstance(DiscordBotService::class);
    app(DiscordBotService::class)->notifyMatchFound(cruceConDiscord());
    app()->terminate();

    Http::assertNothingSent();
});

it('cada jugador recibe su mensaje directo en su idioma', function () {
    foreach ([1 => 'de', 2 => 'en', 3 => null] as $i => $locale) {
        \App\Models\User::create([
            'discord_id' => (string) (100000000000000000 + $i), 'discord_username' => 'u' . $i,
            'name' => 'U' . $i, 'email' => "u{$i}@example.com", 'locale' => $locale,
        ]);
    }

    app(DiscordBotService::class)->notifyMatchFound(cruceConDiscord());
    app()->terminate();

    $titulos = collect(Http::recorded())
        ->map(fn ($par) => $par[0])
        ->filter(fn ($r) => str_contains($r->url(), '/channels/dm-'))
        ->mapWithKeys(fn ($r) => [basename(dirname($r->url())) => $r['embeds'][0]['title']]);

    expect($titulos['dm-100000000000000001'])->toBe('🎯 Kampf gefunden!')
        ->and($titulos['dm-100000000000000002'])->toBe('🎯 Match found!')
        ->and($titulos['dm-100000000000000003'])->toBe('🎯 ¡Match Encontrado!')
        ->and($titulos['dm-100000000000000004'])->toBe('🎯 ¡Match Encontrado!');

    // El idioma de la peticion no se queda cambiado.
    expect(app()->getLocale())->toBe(config('app.locale'));
});

it('el idioma de todos los destinatarios se lee con una sola consulta', function () {
    foreach ([1, 2, 3, 4] as $i) {
        \App\Models\User::create([
            'discord_id' => (string) (100000000000000000 + $i), 'discord_username' => 'q' . $i,
            'name' => 'Q' . $i, 'email' => "q{$i}@example.com", 'locale' => 'fr',
        ]);
    }

    $consultas = 0;
    DB::listen(function ($q) use (&$consultas) {
        $sql = str_replace(['"', '`'], '', $q->sql);

        if (str_contains($sql, 'locale') && str_contains($sql, 'from users')) {
            $consultas++;
        }
    });

    app(DiscordBotService::class)->notifyMatchFound(cruceConDiscord());
    app()->terminate();

    expect($consultas)->toBe(1);
});
