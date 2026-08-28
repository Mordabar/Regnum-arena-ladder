<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppSetting::setValue('premade_vs_random_pl_win_penalty_pct', 20, 'runtime', 'float', false);
        AppSetting::setValue('premade_vs_random_mmr_win_penalty_pct', 14, 'runtime', 'float', false);
    }

    public function down(): void
    {
        AppSetting::setValue('premade_vs_random_pl_win_penalty_pct', 20, 'runtime', 'float', false);
        AppSetting::setValue('premade_vs_random_mmr_win_penalty_pct', 14, 'runtime', 'float', false);
    }
};
