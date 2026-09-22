<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * El panel del enfrentamiento: la zona arriba, el chat con scroll y las dos
 * columnas.
 *
 * Todo esto se arreglo despues de verlo en produccion, asi que lo que hay
 * aqui son redes para que no vuelva solo. Son comprobaciones sobre el CSS y
 * la plantilla -no hay navegador en la suite- pero cada una mira la causa
 * exacta del fallo, no un sintoma.
 */
function hojaDelLayout(): string
{
    return File::get(resource_path('views/layouts/arena.blade.php'));
}

function plantillaDelCombate(): string
{
    return File::get(resource_path('views/components/arena-live-match.blade.php'));
}

it('el historial del chat se puede subir a releer', function () {
    // El fallo: `justify-content: flex-end` en una caja que desborda empuja el
    // contenido mas alla del origen del scroll, y lo que sale por arriba deja
    // de ser alcanzable -la barra no llega-. El chat enseñaba los ultimos
    // cuatro avisos y los anteriores no existian para nadie.
    //
    // La forma que SI funciona es un margen automatico en el primer hijo:
    // baja el grupo cuando sobra sitio y no hace nada cuando falta.
    $css = hojaDelLayout();

    // Todas las reglas de la caja, no solo la primera: la version de dos
    // columnas la vuelve a tocar mas arriba en el fichero.
    preg_match_all('/\.arena-chat-log\s*\{[^}]*\}/', $css, $reglas);

    expect($reglas[0])->not->toBeEmpty();

    foreach ($reglas[0] as $regla) {
        expect($regla)->not->toContain('justify-content: flex-end');
    }

    expect(implode('', $reglas[0]))->toContain('overflow-y: auto');

    expect($css)->toContain('.arena-chat-log > :first-child { margin-top: auto; }');
});

it('la zona se ve sin bajar y el boton llama la atencion', function () {
    $vista = plantillaDelCombate();

    // Arriba del todo: antes vivia en el pie, debajo del escenario, del chat y
    // del formulario de reporte. Con el cruce recien dado, saber donde se
    // queda es lo primero que hace falta.
    $zona = strpos($vista, 'arena-duel-zone is-destacada');
    $escenario = strpos($vista, 'class="arena-battle"');
    $pie = strpos($vista, 'arena-duel-panel-foot');

    expect($zona)->not->toBeFalse()
        ->and($zona)->toBeLessThan($escenario)
        ->and($zona)->toBeLessThan($pie);

    // Y no queda ninguna copia en el pie. (El rotulo `-zone-key` sigue ahi
    // para la linea de "se cierra en cuanto reportes": lo que no puede haber
    // es un segundo boton de zona.)
    expect(substr($vista, $pie))->not->toContain('arena-duel-zone-btn');

    // El boton se marca para que el runtime lo haga latir hasta que se abra
    // el mapa una vez.
    expect($vista)->toContain('data-zone-call="{{ $match->id }}"');
});

it('el aviso de cruce tambien marca su boton de zona', function () {
    // Los dos momentos que pidio el jugador: al darse el match (este panel) y
    // al confirmarse (el de arriba).
    $vista = File::get(resource_path('views/components/arena-duel-panel.blade.php'));

    expect($vista)->toContain('data-zone-call="{{ $match->id }}"');
});

it('el latido de la zona se apaga al abrir el mapa', function () {
    $runtime = File::get(resource_path('views/partials/arena-zona-runtime.blade.php'));

    // Un aviso que sigue puesto despues de haberle hecho caso deja de
    // significar nada, y el panel se repinta solo cada pocos segundos: sin
    // recordarlo, el latido volveria en cada repintado.
    expect($runtime)->toContain("classList.remove('is-llamando')")
        ->and($runtime)->toContain('sessionStorage');

    expect(hojaDelLayout())->toContain('.arena-duel-zone-btn.is-llamando');
});

it('escenario y chat van en dos columnas, y solo si hay chat', function () {
    $vista = plantillaDelCombate();
    $css = hojaDelLayout();

    // `has-chat` solo cuando el combate esta en marcha: sin chat, el escenario
    // se queda a todo lo ancho como estaba.
    expect($vista)->toContain('arena-live-arena @if($running) has-chat @endif');
    expect($css)->toContain('.arena-live-arena.has-chat');

    // Estirar el escenario para llenar la columna no vale: el visor 3D mide el
    // recuadro una sola vez al montarse y las figuras se quedaban diminutas en
    // medio de un cajon vacio. Se centra, no se estira.
    expect($css)->toContain('.arena-live-arena.has-chat .arena-battle { align-content: center; }')
        ->and($css)->not->toContain('.arena-live-arena.has-chat .arena-battle-stage');
});

it('las frases del chat se arrastran de lado en movil y no en escritorio', function () {
    $css = hojaDelLayout();

    // En escritorio, rejilla: con raton no se arrastra, asi que en una barra
    // la mitad de las frases no existian.
    expect(Str::between($css, '.arena-chat-quick {', '}'))->toContain('display: grid');

    // En movil, una sola fila que se empuja: en rejilla ocupaban tres filas
    // justo debajo del historial, que es la mitad de lo que se ve.
    $movil = Str::after($css, '@media (max-width: 720px) {');

    expect($movil)->toContain('overflow-x: auto')
        ->and($movil)->toContain('flex-wrap: nowrap');
});

it('los avisos salen de la pagina cuando la pestaña esta de lado', function () {
    // El fallo que conto el jugador: con la pestaña en otra ventana, ni suena
    // ni alerta; habia que volver a mirar para enterarse, que es justo cuando
    // ya no sirve. Un toast es un div y nadie lo ve desde fuera.
    //
    // Tres capas: notificacion del sistema si hay permiso, el titulo
    // parpadeando -que no pide permiso a nadie- y el toast solo para cuando se
    // esta mirando.
    $css = hojaDelLayout();

    expect($css)->toContain('if (document.hidden) {')
        ->and($css)->toContain('notificarSistema(type, message);')
        ->and($css)->toContain('parpadearTitulo(message);');

    // El permiso se pide con un gesto y nunca al cargar: preguntado en frio se
    // deniega, y denegado no se puede volver a pedir desde la pagina.
    expect($css)->toContain('Notification.requestPermission()')
        ->and($css)->toContain('Notification.permission !== \'default\'');

    // Y el contexto de audio se reanima antes de programar las notas: en
    // segundo plano el navegador lo suspende por su cuenta y todo salia de
    // golpe al volver.
    expect($css)->toContain('if (context.state !== \'running\') {');
});
