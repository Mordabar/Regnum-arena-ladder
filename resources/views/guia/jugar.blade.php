@extends('layouts.arena')

@section('title', 'Cómo jugar - Regnum Arena Ladder')

@section('content')
@php
    $img = fn (string $nombre) => asset('images/guia/' . $nombre . '.webp') . '?v=' . (@filemtime(public_path('images/guia/' . $nombre . '.webp')) ?: '1');

    // Los nueve pasos, con sus capturas. Es el mismo texto que la guia de
    // Discord: quien llega por un sitio o por el otro lee lo mismo.
    $pasos = [
        [
            'id' => 'entrar', 'titulo' => 'Entra con tu cuenta de Discord', 'corto' => 'Entrar con Discord',
            'texto' => 'No hay formularios ni contraseñas nuevas. Abre <b>regnumarenaladder.top</b> y pulsa <b>Entrar con Discord</b>. Autorizas y ya estás dentro.<br>Usamos Discord porque es donde vive la comunidad: así sabemos a quién avisar cuando tengas un cruce o un reporte esperando.',
            'tip' => 'Lo primero que verás al volver es tu lobby vacío, pidiéndote crear tu primer guerrero.',
            'fotos' => [['01-portada', 'La portada, con el botón de entrar arriba y en el centro.'], ['03-lobby-vacio', 'Recién entrado: el lobby te pide tu primer guerrero.']],
        ],
        [
            'id' => 'guerrero', 'titulo' => 'Crea tu guerrero', 'corto' => 'Crear tu guerrero',
            'texto' => 'Tu guerrero es tu personaje de Regnum dentro del ladder. Se hace en cuatro pasos: <b>reino</b>, <b>raza y sexo</b>, <b>subclase</b> y <b>nombre</b>.<br>Según avanzas, la figura se va montando en 3D, así que ves exactamente lo que estás eligiendo antes de confirmar.',
            'tip' => 'Pon el mismo nombre que tienes dentro de Regnum. Es como te reconoce el rival al confirmar el resultado.',
            'ojo' => 'El nombre, la raza y el sexo se pueden cambiar después. El reino y la subclase no. Cada raza solo ofrece las subclases que le corresponden en el juego.',
            'fotos' => [['04-reino', 'Primero el reino.'], ['05-raza', 'Luego raza y sexo.'], ['06-nombre', 'Subclase y nombre, y a crear.']],
        ],
        [
            'id' => 'lobby', 'titulo' => 'Conoce el lobby', 'corto' => 'Conocer el lobby',
            'texto' => 'Todo pasa en esta pantalla. A la izquierda, tu escuadra, con hasta cinco guerreros entre los que cambias con un clic. En el centro, el guerrero activo con sus PL, su MMR y su balance. Debajo, los botones para entrar a combatir.<br>La cola, el cruce, el combate y el reporte ocurren aquí mismo: no hace falta navegar a ninguna otra página. Sobre el escenario tienes el interruptor de modalidad: <b>1v1, 2v2 o 3v3</b>.',
            'tip' => '<b>PL</b> son los puntos de la temporada, los que ordenan el ladder. <b>MMR</b> es tu nivel real, el que usa el sistema para buscarte rivales parecidos. Los dos empiezan igual para todos.',
            'fotos' => [['07-lobby', 'El lobby con tu guerrero activo.'], ['08-modalidad', 'El interruptor de modalidad.']],
        ],
        [
            'id' => 'cola', 'titulo' => 'Entra a la cola', 'corto' => 'Entrar a la cola',
            'texto' => 'Dos maneras de entrar:<br><b>Random</b> — entras solo y el sistema te completa el equipo con gente de tu reino.<br><b>Premade</b> — invitas tú a tus aliados y entráis juntos.<br>Mientras esperas, el panel te dice cuánta gente hay en cola por reino y qué falta exactamente para que se arme el cruce.',
            'tip' => 'No hace falta que te quedes mirando ni que recargues. La pantalla se actualiza sola y suena un aviso cuando aparece rival.',
            'ojo' => 'El equipo random gana más puntos si vence a un premade, y pierde menos si cae. Entrar solo no te castiga.',
            'fotos' => [['11-cola', 'En cola: quién espera y qué falta.'], ['10-invitar', 'Premade: invitas a tus aliados.']],
        ],
        [
            'id' => 'cruce', 'titulo' => 'Acepta el cruce', 'corto' => 'Aceptar el cruce',
            'texto' => 'Cuando hay rival, el aviso se apodera de la pantalla y arranca una cuenta atrás. Ves las dos alineaciones enfrentadas, quién ha aceptado ya y la zona donde toca pelear.<br>Del rival ves su reino y su subclase, pero no su nombre. Eso se revela cuando el enfrentamiento se cierra.',
            'tip' => 'Pulsa sobre la zona y se abre su mapa con el punto de encuentro marcado. Aceptar no te saca de la pantalla.',
            'ojo' => 'Si dejas pasar el reloj sin aceptar, el cruce se cancela para todos. Rechazar cruces a menudo baja tu confianza y te bloquea la cola un rato.',
            'fotos' => [['12-cruce', '¡Combate encontrado! Acepta antes de que acabe el reloj.'], ['13-zona', 'El mapa de la zona con el punto de encuentro.']],
        ],
        [
            'id' => 'combate', 'titulo' => 'Pelea el combate', 'corto' => 'Pelear el combate',
            'texto' => 'Cuando todos aceptan, el panel pasa a combate y empieza el reloj real que tenéis para pelear y reportar. Aquí es cuando os vais a la zona dentro de Regnum y lo resolvéis.<br>Mientras vais de camino podéis avisaros con los botones rápidos: “voy de camino”, “estoy en el punto”…',
            'tip' => 'Haz una captura del final del combate antes de salir. La vas a necesitar en el paso siguiente y no se puede reportar sin ella.',
            'fotos' => [['13-combate', 'El combate en curso, con los avisos rápidos.']],
        ],
        [
            'id' => 'reporte', 'titulo' => 'Reporta el resultado', 'corto' => 'Reportar el resultado',
            'texto' => 'Cualquiera de los dos bandos puede reportar. Eliges qué equipo ganó, subes de una a tres capturas y, si quieres, añades una nota. Todo desde la misma pantalla.<br>Admite JPG, PNG, WEBP, GIF, BMP, AVIF y HEIC, hasta 10 MB cada una.',
            'ojo' => 'Si nadie reporta antes de que venza el reloj, el enfrentamiento se anula y no reparte puntos. Nadie sale perdiendo, pero tampoco ganando.',
            'fotos' => [['14-reporte', 'El formulario del reporte.'], ['15-esperando', 'Reporte enviado: ahora le toca al rival.']],
        ],
        [
            'id' => 'puntos', 'titulo' => 'La confirmación y los puntos', 'corto' => 'Confirmación y puntos',
            'texto' => 'El rival ve tu reporte y lo confirma o lo rechaza con motivo.<ul><li>Si lo <b>confirma</b>, el resultado se cierra y los puntos se reparten al momento.</li><li>Si deja pasar su plazo sin decir nada, el reporte <b>se da por bueno</b>.</li><li>Si lo <b>rechaza</b>, entra en disputa y lo revisa la moderación.</li></ul>',
            'tip' => 'Cuánto subes o bajas depende de contra quién juegues. Ganar a alguien por encima de ti da más que ganar a alguien por debajo.',
            'fotos' => [['16-confirmado', 'Resultado confirmado: tus PL ya se han actualizado.']],
        ],
        [
            'id' => 'ladder', 'titulo' => 'Mira el ladder', 'corto' => 'Mirar el ladder',
            'texto' => 'La clasificación es pública y se actualiza al instante. Arriba, el podio en 3D. Debajo, la tabla completa con PL, MMR y porcentaje de victorias, con buscador y filtros por reino y subclase.<br>1v1, 2v2 y 3v3 comparten una sola tabla, así que todo lo que juegues cuenta para el mismo sitio.',
            'fotos' => [['17-ladder', 'El ladder público.']],
        ],
    ];

    $reglas = [
        ['Random', 'entras con un personaje y el sistema completa tu equipo con gente de tu reino.'],
        ['Premade', 'el equipo exacto, todos del mismo reino y de usuarios distintos, con un límite diario.'],
        ['Random contra premade', 'el equipo random gana más puntos si vence, y pierde menos si cae.'],
        ['Conjuradores', 'solo puede haber uno de soporte por equipo.'],
        ['Anonimato', 'del rival ves reino y subclase, nunca el nombre, hasta que el enfrentamiento se cierra.'],
        ['Reporte', 'quien reporta sube entre una y tres capturas. El rival confirma o rechaza; si deja pasar el plazo, se da por bueno.'],
        ['Sin reporte', 'si nadie reporta antes de que se agote el reloj, el enfrentamiento se anula y no reparte puntos.'],
        ['Abandonos', 'rechazar cruces a menudo o abandonar partidas baja tu confianza y bloquea la cola un tiempo.'],
    ];

    $dudas = [
        ['¿Cuesta algo?', 'No.'],
        ['¿Necesito equipo fijo?', 'No, puedes entrar solo a random.'],
        ['¿Hay que instalar algo?', 'No, funciona en el navegador, en PC y en el móvil.'],
        ['¿Cuántos personajes puedo tener?', 'Hasta cinco en tu escuadra.'],
        ['¿Y si mi rival miente en el reporte?', 'Lo rechazas con motivo y lo revisa la moderación. Por eso las capturas son obligatorias.'],
        ['¿1v1, 2v2 y 3v3 tienen tablas separadas?', 'No, es un único ladder.'],
        ['Encontré un fallo, ¿dónde lo digo?', 'En nuestro Discord. Estamos en alpha.'],
    ];

    $medallas = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
@endphp

<div class="arena-jugar mx-auto max-w-5xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Cómo jugar']]" class="mb-6" />

    {{-- Las dos guias, como pestañas: esta es el paso a paso; "Como funciona"
         explica las reglas y la puntuacion con calma. --}}
    <nav class="arena-guia-tabs" aria-label="Guías">
        <a href="{{ route('como-jugar') }}" class="is-activa" aria-current="page"><x-arena-icon name="book" class="h-4 w-4" />Cómo jugar</a>
        <a href="{{ route('guia') }}"><x-arena-icon name="scale" class="h-4 w-4" />Cómo funciona</a>
    </nav>

    <section class="arena-panel-strong arena-jugar-hero arena-animate-in">
        <p class="arena-kicker">Guía de uso</p>
        <h1 class="arena-jugar-titulo">⚔️ Cómo jugar en Regnum Arena Ladder</h1>
        <p class="arena-jugar-sub">De cero a tu primer combate rankeado, en 9 pasos.</p>
        <p class="arena-jugar-intro arena-body-text">
            Arena Ladder organiza arenas 1v1, 2v2 y 3v3 para Regnum Online. Nosotros ponemos el
            emparejamiento, el marcador y la clasificación. El combate lo ponéis vosotros dentro del juego.
        </p>

        {{-- El indice: los nueve pasos de un vistazo, y cada uno salta al suyo. --}}
        <ol class="arena-jugar-indice">
            @foreach($pasos as $i => $paso)
                <li><a href="#{{ $paso['id'] }}"><span>{{ $i + 1 }}</span>{{ $paso['corto'] }}</a></li>
            @endforeach
        </ol>
    </section>

    @foreach($pasos as $i => $paso)
        <section id="{{ $paso['id'] }}" class="arena-jugar-paso arena-card" aria-labelledby="paso-{{ $paso['id'] }}">
            <header class="arena-jugar-paso-head">
                <span class="arena-guia-num" aria-hidden="true">{{ $i + 1 }}</span>
                <h2 id="paso-{{ $paso['id'] }}">{{ $paso['titulo'] }}</h2>
            </header>

            <div class="arena-jugar-texto arena-body-text">{!! $paso['texto'] !!}</div>

            @isset($paso['tip'])
                <p class="arena-jugar-nota is-tip"><span aria-hidden="true">💡</span><span>{!! $paso['tip'] !!}</span></p>
            @endisset
            @isset($paso['ojo'])
                <p class="arena-jugar-nota is-ojo"><span aria-hidden="true">⚠️</span><span>{!! $paso['ojo'] !!}</span></p>
            @endisset

            <div class="arena-jugar-fotos" data-fotos="{{ count($paso['fotos']) }}">
                @foreach($paso['fotos'] as [$foto, $pie])
                    <figure>
                        <a href="{{ $img($foto) }}" target="_blank" rel="noopener">
                            <img src="{{ $img($foto) }}" alt="{{ $pie }}" loading="lazy" decoding="async">
                        </a>
                        <figcaption>{{ $pie }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="arena-jugar-paso arena-card" aria-labelledby="jugarReglas">
        <header class="arena-jugar-paso-head">
            <span class="arena-guia-num" aria-hidden="true">📜</span>
            <h2 id="jugarReglas">Las reglas, en corto</h2>
        </header>
        <dl class="arena-jugar-reglas">
            @foreach($reglas as [$titulo, $texto])
                <div><dt>{{ $titulo }}</dt><dd>{{ $texto }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="arena-jugar-paso arena-card" aria-labelledby="jugarDudas">
        <header class="arena-jugar-paso-head">
            <span class="arena-guia-num" aria-hidden="true">?</span>
            <h2 id="jugarDudas">Dudas frecuentes</h2>
        </header>
        <div class="arena-jugar-dudas">
            @foreach($dudas as [$pregunta, $respuesta])
                <details>
                    <summary>{{ $pregunta }}</summary>
                    <p>{{ $respuesta }}</p>
                </details>
            @endforeach
        </div>
    </section>

    @if($premios->activos())
        <section class="arena-jugar-paso arena-card arena-jugar-premio" aria-labelledby="jugarPremio">
            <header class="arena-jugar-paso-head">
                <span class="arena-guia-num" aria-hidden="true">💰</span>
                <h2 id="jugarPremio">Y hay premio</h2>
            </header>
            <p class="arena-jugar-texto arena-body-text">
                La temporada reparte <b>{{ $premios->total() }} {{ $premios->moneda() }}</b> entre los
                primeros de la tabla general, sin importar el reino.
            </p>
            <p class="arena-jugar-medallas">
                @foreach($podio as $puesto)
                    <span>{{ $medallas[$puesto['puesto']] ?? '🏅' }} <b>{{ $puesto['premio'] }}</b></span>
                @endforeach
            </p>
        </section>
    @endif

    <section class="arena-panel-strong arena-jugar-fin">
        <p class="arena-jugar-sub">Nos vemos en la arena. ⚔️</p>
        <div class="arena-jugar-fin-botones">
            <a href="{{ route('lobby') }}" class="arena-btn"><x-arena-icon name="bolt" class="h-4 w-4 shrink-0" />Entrar a la arena</a>
            <a href="{{ route('ladder.index') }}" class="arena-btn-ghost"><x-arena-icon name="ladder" class="h-4 w-4 shrink-0" />Ver el ladder</a>
        </div>
    </section>
</div>
@endsection
