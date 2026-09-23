{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach([['home', 'daily', '1.0'], ['ladder.index', 'hourly', '0.9'], ['hall-of-fame', 'daily', '0.8'], ['como-jugar', 'monthly', '0.7'], ['guia', 'monthly', '0.6'], ['descargas', 'monthly', '0.6']] as [$ruta, $frecuencia, $prioridad])
    <url>
        <loc>{{ route($ruta) }}</loc>
        <changefreq>{{ $frecuencia }}</changefreq>
        <priority>{{ $prioridad }}</priority>
    </url>
@endforeach
@foreach($guerreros as $guerrero)
    <url>
        <loc>{{ route('ladder.show', $guerrero) }}</loc>
        @if($guerrero->updated_at)<lastmod>{{ $guerrero->updated_at->toAtomString() }}</lastmod>@endif
        <changefreq>daily</changefreq>
        <priority>0.5</priority>
    </url>
@endforeach
</urlset>
