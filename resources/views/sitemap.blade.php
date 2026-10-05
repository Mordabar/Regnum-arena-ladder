{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
@php
    use App\Support\I18n\Idioma;

    // Cada pagina publica sale una vez, con sus versiones por idioma como
    // alternativas (?lang=xx): asi el buscador sirve a cada persona la suya.
    $versiones = fn (string $url) => collect(Idioma::IDIOMAS)->map(
        fn ($datos, $codigo) => '        <xhtml:link rel="alternate" hreflang="' . $datos['html'] . '" href="' . e($url . '?lang=' . $codigo) . '"/>'
    )->push('        <xhtml:link rel="alternate" hreflang="x-default" href="' . e($url) . '"/>')->implode("\n");
@endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach([['home', 'daily', '1.0'], ['ladder.index', 'hourly', '0.9'], ['hall-of-fame', 'daily', '0.8'], ['como-jugar', 'monthly', '0.7'], ['guia', 'monthly', '0.6'], ['descargas', 'monthly', '0.6']] as [$ruta, $frecuencia, $prioridad])
    <url>
        <loc>{{ route($ruta) }}</loc>
{!! $versiones(route($ruta)) !!}
        <changefreq>{{ $frecuencia }}</changefreq>
        <priority>{{ $prioridad }}</priority>
    </url>
@endforeach
@foreach($guerreros as $guerrero)
    <url>
        <loc>{{ route('ladder.show', $guerrero) }}</loc>
{!! $versiones(route('ladder.show', $guerrero)) !!}
        @if($guerrero->updated_at)<lastmod>{{ $guerrero->updated_at->toAtomString() }}</lastmod>@endif
        <changefreq>daily</changefreq>
        <priority>0.5</priority>
    </url>
@endforeach
</urlset>
