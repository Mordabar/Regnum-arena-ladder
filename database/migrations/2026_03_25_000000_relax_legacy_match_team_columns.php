<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('matches')) {
            return;
        }

        // MODIFY solo existe en MySQL; en sqlite las columnas legacy ya aceptan null.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $columns = collect(Schema::getColumns('matches'))->keyBy('name');

        foreach (['team_ignis', 'team_syrtis', 'team_alsius'] as $legacyColumn) {
            $definition = $columns->get($legacyColumn);

            if ($definition === null) {
                continue;
            }

            $isNullable = (bool) ($definition['nullable'] ?? false);
            $columnType = $definition['type'] ?? null;

            if ($isNullable || $columnType === null) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `matches` MODIFY `%s` %s NULL',
                $legacyColumn,
                $columnType
            ));
        }
    }

    public function down(): void
    {
        // Compatibility migration only.
    }
};
