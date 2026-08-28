<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppSetting::setValue('random_vs_premade_pl_bonus_pct', 25, 'runtime', 'float', false);
        AppSetting::setValue('random_vs_premade_mmr_bonus_pct', 18, 'runtime', 'float', false);
    }

    public function down(): void
    {
        AppSetting::setValue('random_vs_premade_pl_bonus_pct', 12, 'runtime', 'float', false);
        AppSetting::setValue('random_vs_premade_mmr_bonus_pct', 8, 'runtime', 'float', false);
    }
};
