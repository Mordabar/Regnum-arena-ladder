<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')
            ->where('key', 'home_tagline')
            ->whereIn('value', [
                'Conquest PvP 2v2 por reino y subclase',
                'Conquest PvP 3v3 por reino y subclase',
            ])
            ->update(['value' => 'Conquest PvP 3v3 por reino y subclase']);

        DB::table('app_settings')
            ->where('key', 'rules_excerpt')
            ->whereIn('value', [
                'Random y premade 2v2, anonimato rival y ladder automatico.',
                'Random y premade 3v3, anonimato rival y ladder automatico.',
                'Random y premade 2v2, anonimato rival, reporte con 2 capturas y ladder automatico por PL/MMR.',
                'Random y premade 3v3, anonimato rival, reporte con 2 capturas y ladder automatico por PL/MMR.',
                'Random y premade 2v2, anonimato rival, reporte con 2 capturas y ladder automático por PL/MMR.',
                'Random y premade 3v3, anonimato rival, reporte con 2 capturas y ladder automático por PL/MMR.',
                'Random y premade 2v2, anonimato rival, reporte con 1 a 3 capturas y ladder automatico por PL/MMR.',
                'Random y premade 2v2, anonimato rival, reporte con 1 a 3 capturas y ladder automático por PL/MMR.',
            ])
            ->update(['value' => 'Random y premade 3v3, anonimato rival, reporte con 1 a 3 capturas y ladder automatico por PL/MMR.']);
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'rules_excerpt')
            ->where('value', 'Random y premade 3v3, anonimato rival, reporte con 1 a 3 capturas y ladder automatico por PL/MMR.')
            ->update(['value' => 'Random y premade 3v3, anonimato rival, reporte con 2 capturas y ladder automatico por PL/MMR.']);
    }
};
