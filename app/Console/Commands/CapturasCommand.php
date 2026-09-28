<?php

namespace App\Console\Commands;

use App\Services\Matches\EvidenceMaintenance;
use Illuminate\Console\Command;

/**
 * Las capturas que ya estaban en el servidor antes de que se normalizaran.
 *
 * Sin opciones solo cuenta: cuantas hay, cuanto ocupan, cuantas se pueden
 * pasar a WebP y cuantas no las apunta ningun reporte. No toca nada hasta que
 * se le pide.
 */
class CapturasCommand extends Command
{
    protected $signature = 'arena:capturas
        {--comprimir : Pasa a WebP las capturas antiguas}
        {--huerfanas : Borra las capturas que ya no apunta ningun reporte}';

    protected $description = 'Revisa el espacio de las capturas y, si se pide, las comprime o borra las huerfanas.';

    public function handle(EvidenceMaintenance $capturas): int
    {
        $antes = $capturas->summary();

        $this->line(sprintf('Capturas en el servidor: %d (%s)', $antes['files'], $this->mb($antes['bytes'])));
        $this->line(sprintf('  que se pueden pasar a WebP: %d (%s)', $antes['convertible'], $this->mb($antes['convertible_bytes'])));
        $this->line(sprintf('  huerfanas (ningun reporte las usa): %d (%s)', $antes['orphans'], $this->mb($antes['orphan_bytes'])));

        if (!$this->option('comprimir') && !$this->option('huerfanas')) {
            $this->newLine();
            $this->comment('No se ha tocado nada. Para actuar: --comprimir y/o --huerfanas');

            return self::SUCCESS;
        }

        if ($this->option('comprimir')) {
            $r = $capturas->compress();
            $this->info(sprintf('Comprimidas %d, ahorrados %s (%d se dejaron como estaban).', $r['converted'], $this->mb($r['saved_bytes']), $r['skipped']));
        }

        if ($this->option('huerfanas')) {
            $r = $capturas->deleteOrphans();
            $this->info(sprintf('Borradas %d huerfanas, liberados %s.', $r['deleted'], $this->mb($r['freed_bytes'])));
        }

        return self::SUCCESS;
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
}
