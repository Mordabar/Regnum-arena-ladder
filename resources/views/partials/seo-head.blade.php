{{-- Identidad del sitio hacia fuera: el icono de la pestaña y lo que se ve al
     compartir un enlace (Discord, WhatsApp, Facebook, X...). Sin esto cada
     enlace salia como texto pelado, sin imagen ni descripcion.

     Cada pagina puede dar su propia descripcion con @section('description');
     si no, se usa la general. Solo se ofrecen a los buscadores las paginas
     publicas: el lobby, los combates o el panel no son para indexar. --}}
@php
    $seoSitio = 'Regnum Arena Ladder';
    $seoTitulo = trim($__env->yieldContent('title', $seoSitio));
    // Lo que llega de @section ya viene escapado por Blade: se imprime tal
    // cual para no escaparlo dos veces.
    $seoDescripcion = trim($__env->yieldContent('description'))
        ?: 'Arena PvP de Regnum Online: duelos 1v1 y arenas 2v2 y 3v3 entre Alsius, Ignis y Syrtis. Ranking por reino y subclase, avisos de cruce y premios por temporada.';
    $seoVersion = fn (string $ruta) => asset($ruta) . '?v=' . (@filemtime(public_path($ruta)) ?: '1');
    $seoImagen = $seoVersion('images/og-arena-ladder.jpg');
    $idiomaActual = \App\Support\I18n\Idioma::actual();
    // Cada idioma es una version de la misma pagina con su propia direccion
    // (?lang=xx): el canonical de una version es ella misma, y las demas se
    // anuncian como alternativas para que cada buscador sirva la suya.
    $seoUrl = $idiomaActual === \App\Support\I18n\Idioma::FUENTE ? url()->current() : url()->current() . '?lang=' . $idiomaActual;
    $seoIndexable = request()->routeIs('home', 'ladder.index', 'ladder.show', 'hall-of-fame', 'como-jugar', 'guia', 'descargas');
@endphp
<meta name="description" content="{!! $seoDescripcion !!}">
<meta name="robots" content="{{ $seoIndexable ? 'index, follow, max-image-preview:large' : 'noindex, follow' }}">
<link rel="canonical" href="{{ $seoUrl }}">
@if($seoIndexable)
@foreach(\App\Support\I18n\Idioma::IDIOMAS as $seoCodigo => $seoDatos)
<link rel="alternate" hreflang="{{ $seoDatos['html'] }}" href="{{ $seoCodigo === \App\Support\I18n\Idioma::FUENTE ? url()->current() : url()->current() . '?lang=' . $seoCodigo }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ url()->current() }}">
@endif

<link rel="icon" href="{{ $seoVersion('favicon.ico') }}" sizes="48x48">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $seoVersion('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $seoVersion('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ $seoVersion('apple-touch-icon.png') }}">

<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $seoSitio }}">
<meta property="og:locale" content="{{ \App\Support\I18n\Idioma::IDIOMAS[\App\Support\I18n\Idioma::actual()]['og'] }}">
<meta property="og:title" content="{!! $seoTitulo !!}">
<meta property="og:description" content="{!! $seoDescripcion !!}">
<meta property="og:url" content="{{ $seoUrl }}">
<meta property="og:image" content="{{ $seoImagen }}">
<meta property="og:image:secure_url" content="{{ $seoImagen }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Regnum Arena Ladder · Conquest PvP">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{!! $seoTitulo !!}">
<meta name="twitter:description" content="{!! $seoDescripcion !!}">
<meta name="twitter:image" content="{{ $seoImagen }}">
<meta name="twitter:image:alt" content="Regnum Arena Ladder · Conquest PvP">

@if(request()->routeIs('home'))
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            'name' => $seoSitio,
            'alternateName' => 'Arena Ladder',
            'url' => url('/'),
            'inLanguage' => 'es',
            'description' => html_entity_decode($seoDescripcion, ENT_QUOTES),
        ],
        [
            '@type' => 'Organization',
            'name' => $seoSitio,
            'url' => url('/'),
            'logo' => asset('images/icono-512.png'),
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endif
