@extends('layouts.arena')

@section('title', 'Como funciona - Regnum Arena Ladder')

@section('content')
@php
    use App\Models\Player as PlayerModel;
@endphp

<div class="mx-auto max-w-5xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Como funciona']]" class="mb-6" />

    {{-- La guia.

         Todo esto vivia en la portada, debajo del podio: tres pasos, tres
         cuadros de caracteristicas y un parrafo de reglas. En la portada era
         ruido -quien llega quiere ver el juego y los premios, no un manual-,
         pero hace falta en alguna parte: quien se plantea entrar tiene
         preguntas legitimas sobre el anonimato, la puntuacion y que pasa si el
         rival no aparece. Aqui tienen sitio para contestarse bien. --}}
    <section class="arena-panel-strong mb-10 p-6 md:p-8 arena-animate-in">
        <p class="arena-kicker">Guia</p>
        <h1 class="mt-3 text-4xl font-bold text-[color:var(--arena-gold-soft)]">Como funciona el Arena Ladder</h1>
        <p class="mt-3 max-w-3xl text-[color:var(--arena-sand)] arena-body-text">
            Buscas contrincante, quedais en un punto del mapa, pelead y reportas quien gano.
            El ladder hace el resto. Aqui esta todo lo demas: lo que se ve del rival, como se
            puntua y que pasa cuando algo sale mal.
        </p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('lobby') }}" class="arena-btn">Entrar a la arena</a>
            <a href="{{ route('ladder.index') }}" class="arena-btn-ghost">Ver el ladder</a>
        </div>
    </section>

    {{-- ── LOS TRES PASOS ── --}}
    <section class="mb-12" aria-labelledby="guiaPasos">
        <h2 id="guiaPasos" class="text-2xl font-semibold text-white">De cero a tu primer combate</h2>

        <div class="mt-6 grid gap-5 md:grid-cols-3">
            <article class="arena-card arena-animate-in arena-stagger-1 p-6">
                <span class="arena-guia-num" aria-hidden="true">1</span>
                <h3 class="mt-4 text-xl font-semibold text-white">Registra tu guerrero</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Entras con Discord y creas tu personaje: nombre, reino, raza y subclase.
                    Puedes tener hasta cinco y elegir con cual juegas cada vez, pero solo uno
                    puede estar en cola a la vez.
                </p>
            </article>

            <article class="arena-card arena-animate-in arena-stagger-2 p-6">
                <span class="arena-guia-num" aria-hidden="true">2</span>
                <h3 class="mt-4 text-xl font-semibold text-white">Elige modalidad y entra a cola</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Duelo 1v1, 2v2 o 3v3, y las tres suman al mismo ladder. En 2v2 y 3v3 puedes
                    entrar solo -el sistema te arma el equipo- o con los tuyos, en premade.
                    Cuando hay cruce, se asigna una zona de la frontera de vuestros reinos y se
                    marca en el mapa el punto exacto donde quedar.
                </p>
            </article>

            <article class="arena-card arena-animate-in arena-stagger-3 p-6">
                <span class="arena-guia-num" aria-hidden="true">3</span>
                <h3 class="mt-4 text-xl font-semibold text-white">Pelea, reporta y cierra</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Durante el combate teneis un chat rapido para avisaros -voy de camino,
                    estoy en el punto, me han matado-. Al acabar, uno sube las capturas y dice
                    quien gano; el otro confirma y el ladder actualiza PL y MMR solo.
                </p>
            </article>
        </div>
    </section>

    {{-- ── LO QUE SE VE DEL RIVAL ── --}}
    <section class="mb-12" aria-labelledby="guiaAnonimato">
        <h2 id="guiaAnonimato" class="text-2xl font-semibold text-white">Que se ve del rival</h2>

        <div class="mt-6 grid gap-5 md:grid-cols-2">
            <article class="arena-panel p-6">
                <h3 class="text-lg font-semibold text-[color:var(--arena-gold-soft)]">En 2v2 y 3v3: anonimato</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Del rival solo ves su reino y su subclase hasta que el combate se cierra o
                    entra en disputa. Ni nombre, ni raza, ni enlace a su perfil. Asi nadie
                    prepara la pelea contra una persona concreta antes de empezarla.
                </p>
            </article>

            <article class="arena-panel p-6">
                <h3 class="text-lg font-semibold text-[color:var(--arena-gold-soft)]">En el duelo 1v1: nombre al aceptar</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Aqui el nombre aparece en cuanto aceptas, nunca antes. Antes de confirmar
                    solo ves el reino y la clase: no se puede elegir rival mirando contra quien
                    te toca. Una vez dentro, los dos sabeis a quien buscar en la zona.
                </p>
            </article>
        </div>
    </section>

    {{-- ── COMO SE PUNTUA ── --}}
    <section class="mb-12" aria-labelledby="guiaPuntos">
        <h2 id="guiaPuntos" class="text-2xl font-semibold text-white">Como se puntua</h2>

        <div class="mt-6 grid gap-5 md:grid-cols-2">
            <article class="arena-panel p-6">
                <h3 class="text-lg font-semibold text-[color:var(--arena-gold-soft)]">PL: lo que se ve</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Los Puntos de Ladder son la tabla publica y lo que decide los premios de la
                    temporada. Ganar suma, perder resta, y ganarle a alguien muy por encima de
                    ti suma mas -el bonus del que no era favorito-.
                </p>
            </article>

            <article class="arena-panel p-6">
                <h3 class="text-lg font-semibold text-[color:var(--arena-gold-soft)]">MMR: lo que no se ve</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Es la puntuacion oculta con la que el sistema busca rival. No sale en
                    ninguna tabla a proposito: sirve para emparejarte con gente de tu nivel, no
                    para presumir. Un premade se empareja con algo mas de exigencia que quien
                    entra suelto.
                </p>
            </article>
        </div>
    </section>

    {{-- ── CUANDO ALGO SALE MAL ── --}}
    <section class="mb-12" aria-labelledby="guiaProblemas">
        <h2 id="guiaProblemas" class="text-2xl font-semibold text-white">Cuando algo sale mal</h2>

        <div class="mt-6 grid gap-5 md:grid-cols-3">
            <article class="arena-card p-6">
                <h3 class="text-lg font-semibold text-white">El rival no aparece</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Avisas desde el propio combate y adjuntas capturas. Quien abandona de forma
                    repetida se queda sin cola durante 12 horas.
                </p>
            </article>

            <article class="arena-card p-6">
                <h3 class="text-lg font-semibold text-white">No estais de acuerdo</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Si rechazas el reporte del rival, el combate pasa a disputa y lo resuelve
                    moderacion mirando las capturas de los dos.
                </p>
            </article>

            <article class="arena-card p-6">
                <h3 class="text-lg font-semibold text-white">Farmeo entre amigos</h3>
                <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
                    Repetir rival seguido esta limitado y el sistema evita cruzaros otra vez
                    durante un rato. Los combates amañados se revierten.
                </p>
            </article>
        </div>
    </section>

    {{-- ── LOS PREMIOS ── --}}
    @if($premios->activos())
        <section class="mb-12" aria-labelledby="guiaPremios">
            <h2 id="guiaPremios" class="text-2xl font-semibold text-white">Que se gana</h2>
            <p class="mt-2 max-w-3xl text-sm text-[color:var(--arena-muted)] arena-body-text">
                Cada temporada reparte premios entre los tres primeros del ladder, y el podio
                se queda para siempre en el <a href="{{ route('hall-of-fame') }}" class="text-[color:var(--arena-gold-soft)] underline">Salon de la Fama</a>.
            </p>

            <div class="mt-6">
                <x-arena-podium :podio="$podio" :premios="$premios" />
            </div>
        </section>
    @endif

    <section class="arena-panel-strong p-6 md:p-8 text-center arena-animate-in">
        <h2 class="text-2xl font-semibold text-[color:var(--arena-gold-soft)]">Ya esta, no hay mas</h2>
        <p class="mt-2 text-sm text-[color:var(--arena-muted)] arena-body-text">
            Lo demas se aprende jugando. Elige guerrero y entra a la cola.
        </p>
        <a href="{{ route('lobby') }}" class="arena-btn mt-6">Entrar a la arena</a>
    </section>
</div>
@endsection
