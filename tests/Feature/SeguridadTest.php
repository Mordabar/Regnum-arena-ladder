<?php

use App\Models\AdminAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('todas las respuestas llevan las cabeceras de seguridad', function () {
    $respuesta = $this->get('/');

    $respuesta->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $csp = $respuesta->headers->get('Content-Security-Policy');
    expect($csp)
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("'wasm-unsafe-eval'")
        ->and($respuesta->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('hsts solo por https', function () {
    $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('las paginas de error tambien llevan las cabeceras', function () {
    $this->get('/no-existe-esta-pagina')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
});

it('desactivar una cuenta de admin cierra sus sesiones abiertas', function () {
    $sesion = sesionDeAdmin();

    $this->withSession($sesion)->get(route('admin.dashboard'))->assertOk();

    AdminAccount::query()->whereKey($sesion['arena_admin.account_id'])->update(['is_active' => false]);

    $this->withSession($sesion)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
});

it('una sesion de admin sin cuenta detras no vale', function () {
    $this->withSession(['arena_admin.authenticated' => true, 'arena_admin.account_id' => 999])
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.login'));

    $this->withSession(['arena_admin.authenticated' => true])
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.login'));
});

it('borrar la cuenta de admin tambien la cierra', function () {
    $sesion = sesionDeAdmin();
    AdminAccount::query()->whereKey($sesion['arena_admin.account_id'])->delete();

    $this->withSession($sesion)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
});
