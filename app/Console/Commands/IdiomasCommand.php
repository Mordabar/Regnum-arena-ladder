<?php

namespace App\Console\Commands;

use App\Support\I18n\Idioma;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Mantenimiento de las traducciones.
 *
 * La lista de frases a traducir vive en lang/_claves.json (todas en español) y
 * cada idioma tiene su catalogo en lang/xx.json. Esto dice cuanto falta, anade
 * las frases nuevas que se hayan anotado y, si se quiere, rellena los huecos con
 * Google Translate para no dejarlos en español mientras se revisan a mano.
 */
class IdiomasCommand extends Command
{
    protected $signature = 'arena:idiomas
        {--faltan= : Lista las frases sin traducir de un idioma (en, pt, de, fr, nl)}
        {--anotar : Suma a la lista las frases anotadas con ARENA_I18N_RECORD (storage/app/i18n-pendientes.json)}
        {--google= : Rellena con Google Translate los huecos de un idioma (o "todos")}';

    protected $description = 'Estado de las traducciones, frases nuevas y relleno automatico de huecos.';

    public function handle(): int
    {
        if ($this->option('anotar')) {
            $this->anotar();
        }

        if ($idioma = $this->option('google')) {
            $codigos = $idioma === 'todos' ? array_diff(Idioma::codigos(), [Idioma::FUENTE]) : [$idioma];

            foreach ($codigos as $codigo) {
                if (!Idioma::esValido($codigo) || $codigo === Idioma::FUENTE) {
                    $this->error("Idioma no valido: {$codigo}");

                    return self::FAILURE;
                }

                $this->rellenarConGoogle($codigo);
            }
        }

        if ($idioma = $this->option('faltan')) {
            foreach ($this->faltan($idioma) as $clave) {
                $this->line($clave);
            }

            return self::SUCCESS;
        }

        $this->estado();

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function claves(): array
    {
        $ruta = lang_path('_claves.json');
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : [];

        return is_array($datos) ? array_values($datos) : [];
    }

    /** @return array<string, string> */
    private function catalogo(string $idioma): array
    {
        $ruta = lang_path($idioma . '.json');
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : [];

        return is_array($datos) ? $datos : [];
    }

    /** @return list<string> */
    private function faltan(string $idioma): array
    {
        $catalogo = $this->catalogo($idioma);

        return array_values(array_filter($this->claves(), fn (string $k) => ($catalogo[$k] ?? '') === ''));
    }

    private function estado(): void
    {
        $total = count($this->claves());
        $filas = [];

        foreach (Idioma::IDIOMAS as $codigo => $datos) {
            if ($codigo === Idioma::FUENTE) {
                continue;
            }

            $faltan = count($this->faltan($codigo));
            $filas[] = [$datos['nombre'], $codigo, $total - $faltan . ' / ' . $total, $total > 0 ? round(($total - $faltan) / $total * 100) . '%' : '—'];
        }

        $this->table(['Idioma', 'Codigo', 'Traducidas', 'Cobertura'], $filas);
    }

    private function anotar(): void
    {
        $ruta = storage_path('app/i18n-pendientes.json');
        $anotadas = is_file($ruta) ? array_keys(json_decode((string) file_get_contents($ruta), true) ?: []) : [];
        $claves = $this->claves();
        $nuevas = array_values(array_diff($anotadas, $claves));
        sort($nuevas);

        file_put_contents(lang_path('_claves.json'), json_encode(array_merge($claves, $nuevas), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        $this->info(count($nuevas) . ' frase(s) nueva(s) anadida(s) a lang/_claves.json.');
    }

    /**
     * Rellena los huecos con la API publica de Google Translate.
     *
     * Protege lo que no hay que traducir -{0}, :name, <b>- cambiandolo por
     * marcas que Google respeta, y lo devuelve despues. Es un relleno, no una
     * revision: lo mejor es repasarlo a mano.
     */
    private function rellenarConGoogle(string $idioma): void
    {
        $catalogo = $this->catalogo($idioma);
        $destino = $idioma === 'pt' ? 'pt' : $idioma;
        $hechas = 0;

        foreach ($this->faltan($idioma) as $clave) {
            $marcas = [];
            $protegido = preg_replace_callback('/\{\d+\}|:[a-z]+|<\/?[a-z]+>/i', function (array $m) use (&$marcas) {
                $marcas[] = $m[0];

                return '[[' . (count($marcas) - 1) . ']]';
            }, $clave);

            $respuesta = Http::timeout(10)->get('https://translate.googleapis.com/translate_a/single', [
                'client' => 'gtx', 'sl' => 'es', 'tl' => $destino, 'dt' => 't', 'q' => $protegido,
            ]);

            if (!$respuesta->successful()) {
                $this->warn('Google no respondio (' . $respuesta->status() . '). Se para aqui; vuelve a ejecutarlo mas tarde.');
                break;
            }

            $trozos = $respuesta->json('0') ?? [];
            $texto = implode('', array_map(fn ($t) => $t[0] ?? '', $trozos));
            $texto = preg_replace_callback('/\[\[\s*(\d+)\s*\]\]/', fn (array $m) => $marcas[(int) $m[1]] ?? $m[0], $texto);

            if (trim((string) $texto) !== '') {
                $catalogo[$clave] = trim($texto);
                $hechas++;
            }

            usleep(150000);
        }

        ksort($catalogo);
        file_put_contents(lang_path($idioma . '.json'), json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
        $this->info("{$idioma}: {$hechas} frase(s) rellenadas con Google.");
    }
}
