<?php

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Services\Matches\EvidenceStorage;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Las capturas de los jugadores se guardan normalizadas: 1920 px como mucho,
 * en WebP y sin metadatos. Una captura de 10 MB pasaba tal cual al disco
 * contado de Hostinger, con el GPS del movil dentro.
 */

function capturaDePrueba(int $ancho, int $alto, string $formato = 'png'): UploadedFile
{
    // Una captura real del juego reescalada: determinista y con el contenido
    // de verdad (texto, barras, escenario). Con ruido aleatorio a veces el PNG
    // comprimia mejor que el WebP y el test fallaba por azar.
    $real = imagecreatefromwebp(public_path('images/guia/13-combate.webp'));
    $img = imagescale($real, $ancho, $alto);
    imagedestroy($real);

    $ruta = tempnam(sys_get_temp_dir(), 'cap');
    $formato === 'jpg' ? imagejpeg($img, $ruta, 95) : imagepng($img, $ruta);
    imagedestroy($img);

    return new UploadedFile($ruta, 'captura.' . $formato, $formato === 'jpg' ? 'image/jpeg' : 'image/png', null, true);
}

function cruceParaCapturas(): ArenaMatch
{
    $match = new ArenaMatch();
    $match->match_code = 'ARENA-7777';

    return $match;
}

it('guarda la captura en webp y como mucho a 1920 px', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    $ruta = app(EvidenceStorage::class)->store(cruceParaCapturas(), capturaDePrueba(3840, 2160), 'evidence-1');

    expect($ruta)->toEndWith('.webp')->toContain('arena-7777');

    $guardada = Storage::disk(MatchReport::EVIDENCE_DISK)->get($ruta);
    [$ancho, $alto, $tipo] = getimagesizefromstring($guardada);

    expect($tipo)->toBe(IMAGETYPE_WEBP)
        ->and($ancho)->toBe(1920)
        ->and($alto)->toBe(1080)
        ->and(strlen($guardada))->toBeLessThan(1024 * 1024);
});

it('no agranda una captura pequeña', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    $ruta = app(EvidenceStorage::class)->store(cruceParaCapturas(), capturaDePrueba(800, 600, 'jpg'), 'evidence-1');
    [$ancho, $alto] = getimagesizefromstring(Storage::disk(MatchReport::EVIDENCE_DISK)->get($ruta));

    expect([$ancho, $alto])->toBe([800, 600]);
});

it('quita los metadatos de la foto', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    // Un JPEG con un bloque EXIF con texto reconocible.
    $captura = capturaDePrueba(640, 480, 'jpg');
    $jpeg = file_get_contents($captura->getRealPath());
    $exif = "Exif\0\0" . 'MM' . "\0*\0\0\0\x08" . str_repeat("\0", 6) . 'GPS-SECRETO-DEL-MOVIL';
    $segmento = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
    file_put_contents($captura->getRealPath(), substr($jpeg, 0, 2) . $segmento . substr($jpeg, 2));

    $ruta = app(EvidenceStorage::class)->store(cruceParaCapturas(), $captura, 'evidence-1');

    expect(Storage::disk(MatchReport::EVIDENCE_DISK)->get($ruta))->not->toContain('GPS-SECRETO-DEL-MOVIL');
});

it('si la imagen no se puede convertir guarda la original antes que perder la prueba', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    // Es una imagen para el servidor, pero no se puede decodificar: el mismo
    // camino que un HEIC en un hosting sin soporte.
    // Un PNG de verdad cortado por la mitad.
    $png = file_get_contents(capturaDePrueba(400, 300)->getRealPath());
    $ruta = tempnam(sys_get_temp_dir(), 'cap');
    file_put_contents($ruta, substr($png, 0, 120));
    $fichero = new UploadedFile($ruta, 'captura.png', 'image/png', null, true);

    $guardada = app(EvidenceStorage::class)->store(cruceParaCapturas(), $fichero, 'evidence-1');

    expect($guardada)->toEndWith('.png')
        ->and(Storage::disk(MatchReport::EVIDENCE_DISK)->get($guardada))->toStartWith("\x89PNG");
});

it('nunca guarda un svg: puede llevar javascript', function () {
    Storage::fake(MatchReport::EVIDENCE_DISK);

    $ruta = tempnam(sys_get_temp_dir(), 'cap');
    file_put_contents($ruta, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $fichero = new UploadedFile($ruta, 'captura.svg', 'image/svg+xml', null, true);

    expect(fn () => app(EvidenceStorage::class)->store(cruceParaCapturas(), $fichero, 'evidence-1'))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk(MatchReport::EVIDENCE_DISK)->allFiles())->toBe([]);
});

function reporteConCapturasViejas(): MatchReport
{
    $user = User::create(['discord_id' => 'cap-1', 'discord_username' => 'cap', 'name' => 'Cap', 'email' => 'cap@example.com']);
    $player = Player::create(['user_id' => $user->id, 'character_name' => 'Cap', 'subclass' => 'knight', 'realm' => 'ignis', 'pl_points' => 0, 'mmr' => 1000, 'trust_score' => 100, 'is_active' => true]);
    $fila = ['player_id' => $player->id, 'character_name' => 'Cap', 'subclass' => 'knight', 'realm' => 'ignis', 'discord_id' => 'cap-1', 'conjurer_role' => null];

    $match = ArenaMatch::create([
        'match_code' => 'ARENA-OLD1', 'report_token' => 'OLDCAPTURA', 'queue_mode' => 'random',
        'team_a_realm' => 'ignis', 'team_b_realm' => 'alsius', 'team_a' => [$fila], 'team_b' => [$fila],
        'zone' => 'emerald_pass', 'status' => 'completed', 'estimated_mmr_avg' => 1000,
        'started_at' => now(), 'expires_at' => now()->addMinutes(30),
    ]);

    $disco = Storage::disk(MatchReport::EVIDENCE_DISK);
    foreach (['final.png', 'extra.jpg', 'rechazo.png'] as $i => $nombre) {
        $disco->put('match-reports/2026/01/arena-old1/' . $nombre, file_get_contents(capturaDePrueba(2400, 1350, $i === 1 ? 'jpg' : 'png')->getRealPath()));
    }

    return MatchReport::create([
        'match_id' => $match->id, 'reported_by_player_id' => $player->id, 'reporting_team' => 'team_a',
        'claimed_winner_team' => 'team_a', 'claimed_winner_realm' => 'ignis', 'status' => 'confirmed',
        'final_screenshot_path' => 'match-reports/2026/01/arena-old1/final.png',
        'encounter_screenshot_path' => 'match-reports/2026/01/arena-old1/final.png',
        'evidence_paths' => ['match-reports/2026/01/arena-old1/final.png', 'match-reports/2026/01/arena-old1/extra.jpg'],
        'rejection_evidence_paths' => ['match-reports/2026/01/arena-old1/rechazo.png'],
    ]);
}

it('arena:capturas sin opciones solo cuenta y no toca nada', function () {
    $reporte = reporteConCapturasViejas();

    $this->artisan('arena:capturas')->expectsOutputToContain('se pueden pasar a WebP: 3')->assertSuccessful();

    expect(Storage::disk(MatchReport::EVIDENCE_DISK)->exists($reporte->final_screenshot_path))->toBeTrue();
});

it('arena:capturas --comprimir pasa las viejas a webp y apunta los reportes a las nuevas', function () {
    $reporte = reporteConCapturasViejas();
    $disco = Storage::disk(MatchReport::EVIDENCE_DISK);

    $this->artisan('arena:capturas --comprimir')->assertSuccessful();

    $reporte->refresh();
    $todas = array_merge([$reporte->final_screenshot_path, $reporte->encounter_screenshot_path], $reporte->evidence_paths, $reporte->rejection_evidence_paths);

    foreach ($todas as $ruta) {
        expect($ruta)->toEndWith('.webp')
            ->and($disco->exists($ruta))->toBeTrue()
            ->and(getimagesizefromstring($disco->get($ruta))[0])->toBeLessThanOrEqual(1920);
    }

    expect($disco->exists('match-reports/2026/01/arena-old1/final.png'))->toBeFalse()
        ->and($disco->exists('match-reports/2026/01/arena-old1/extra.jpg'))->toBeFalse();
});

it('arena:capturas --huerfanas borra solo lo viejo que ningun reporte usa', function () {
    reporteConCapturasViejas();
    $disco = Storage::disk(MatchReport::EVIDENCE_DISK);

    $disco->put('match-reports/2025/12/arena-perdida/vieja.png', 'x');
    touch($disco->path('match-reports/2025/12/arena-perdida/vieja.png'), now()->subDays(3)->getTimestamp());
    // Recien subida: puede ser una subida a medias cuya fila aun no existe.
    $disco->put('match-reports/2026/01/arena-nueva/recien.png', 'x');

    $this->artisan('arena:capturas --huerfanas')->assertSuccessful();

    expect($disco->exists('match-reports/2025/12/arena-perdida/vieja.png'))->toBeFalse()
        ->and($disco->exists('match-reports/2026/01/arena-nueva/recien.png'))->toBeTrue()
        ->and($disco->exists('match-reports/2026/01/arena-old1/final.png'))->toBeTrue();
});

it('las capturas se sirven sin poder ejecutar nada', function () {
    $reporte = reporteConCapturasViejas();

    $this->actingAs(User::where('discord_id', 'cap-1')->first())
        ->get($reporte->evidenceUrl('evidence-1'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
});

it('un gif animado enorme no bloquea la subida', function () {
    // El arbitro lo encontro: con los fotogramas decodificados, un GIF de
    // 300 KB con 40 fotogramas de 4000x2500 tardaba 40 s y la peticion moria.
    // Aqui, 40 de 1600x1000: antes unos 6 s, ahora una fraccion de segundo.
    Storage::fake(MatchReport::EVIDENCE_DISK);
    $m = new Intervention\Image\ImageManager(new Intervention\Image\Drivers\Gd\Driver());
    $anim = $m->animate(function ($a) use ($m) {
        for ($i = 0; $i < 40; $i++) {
            $a->add($m->create(1600, 1000)->fill(sprintf('#%06x', $i * 4000)), 0.1);
        }
    });
    $ruta = tempnam(sys_get_temp_dir(), 'gif');
    file_put_contents($ruta, (string) $anim->toGif());

    $inicio = microtime(true);
    $guardada = app(EvidenceStorage::class)->store(cruceParaCapturas(), new UploadedFile($ruta, 'a.gif', 'image/gif', null, true), 'evidence-1');

    expect(microtime(true) - $inicio)->toBeLessThan(2)
        ->and($guardada)->toEndWith('.webp');
});

it('el servidor rechaza un svg en el reporte, el rechazo y el abandono', function () {
    $reporte = reporteConCapturasViejas();
    $user = User::where('discord_id', 'cap-1')->first();
    $svg = UploadedFile::fake()->createWithContent('captura.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->actingAs($user)->post(route('matches.report'), [
        'match_id' => $reporte->match_id, 'player_id' => $reporte->reported_by_player_id,
        'claimed_winner_team' => 'team_a', 'evidence_files' => [$svg],
    ])->assertSessionHasErrors('evidence_files.0');

    $this->actingAs($user)->post(route('matches.report.reject'), [
        'report_id' => $reporte->id, 'player_id' => $reporte->reported_by_player_id,
        'rejection_note' => 'No fue asi de ninguna manera', 'rejection_files' => [$svg],
    ])->assertSessionHasErrors('rejection_files.0');

    $this->actingAs($user)->post(route('matches.abandonment.report'), [
        'match_id' => $reporte->match_id, 'player_id' => $reporte->reported_by_player_id,
        'accused_player_id' => $reporte->reported_by_player_id, 'note' => 'Se fue al principio', 'files' => [$svg],
    ])->assertSessionHasErrors('files.0');

    expect(Storage::disk(MatchReport::EVIDENCE_DISK)->allFiles('match-reports/' . now()->format('Y/m')))->toBe([]);
});

it('con imagick tambien se lee solo el primer fotograma', function () {
    // Con la ruta, Imagick carga y recompone todos los fotogramas antes de
    // mirar decodeAnimation (17 s y error con un GIF de 40 fotogramas grandes).
    if (!extension_loaded('imagick')) {
        $this->markTestSkipped('Sin la extension imagick.');
    }

    $m = new Intervention\Image\ImageManager(new Intervention\Image\Drivers\Gd\Driver());
    $anim = $m->animate(function ($a) use ($m) {
        for ($i = 0; $i < 30; $i++) {
            $a->add($m->create(1600, 1000)->fill(sprintf('#%06x', $i * 4000)), 0.1);
        }
    });
    $ruta = tempnam(sys_get_temp_dir(), 'gif');
    file_put_contents($ruta, (string) $anim->toGif());

    $origen = (new ReflectionMethod(EvidenceStorage::class, 'source'))->invoke(app(EvidenceStorage::class), 'imagick', $ruta);

    expect($origen)->toBeInstanceOf(Imagick::class)
        ->and($origen->getNumberImages())->toBe(1);
});
