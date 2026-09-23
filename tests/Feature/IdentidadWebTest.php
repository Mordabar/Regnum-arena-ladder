<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function guerreroSeo(string $nombre): Player
{
    $user = User::create(['discord_id' => 'seo-' . md5($nombre), 'discord_username' => 'seo', 'name' => 'Seo', 'email' => md5($nombre) . '@example.com']);

    return Player::create(['user_id' => $user->id, 'character_name' => $nombre, 'subclass' => 'knight', 'realm' => 'ignis', 'pl_points' => 0, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true]);
}

it('la portada lleva favicon, imagen para compartir y descripcion', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('rel="icon"')
        ->toContain('apple-touch-icon.png')
        ->toContain('property="og:image"')
        ->toContain('images/og-arena-ladder.jpg')
        ->toContain('name="twitter:card" content="summary_large_image"')
        ->toContain('application/ld+json')
        ->toContain('index, follow');

    foreach (['favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png', 'images/og-arena-ladder.jpg', 'images/icono-maskable-512.png', 'robots.txt'] as $fichero) {
        expect(is_file(public_path($fichero)))->toBeTrue($fichero);
    }
});

it('la ficha de un guerrero se comparte con su nombre, escapado una sola vez', function () {
    $player = guerreroSeo('Tom & Jerry');

    $html = $this->get(route('ladder.show', $player))->assertOk()->getContent();

    expect($html)
        ->toContain('Tom &amp; Jerry')
        ->not->toContain('&amp;amp;');
});

it('el sitemap lista las paginas publicas en xml', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('ladder.index'), false)
        ->assertSee(route('descargas'), false)
        ->assertDontSee('/lobby', false);
});

it('las paginas de un jugador no se ofrecen a los buscadores', function () {
    $player = guerreroSeo('Anonimo');

    $this->actingAs($player->user)
        ->get(route('lobby'))
        ->assertOk()
        ->assertSee('noindex, follow', false);
});
