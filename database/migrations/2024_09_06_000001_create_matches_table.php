<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deprecated duplicate migration.
        // The canonical MVP v1 matches schema is defined in
        // 2024_01_03_000001_create_matches_table.php.
    }

    public function down(): void
    {
        // No-op. Kept only so historical migration order remains stable.
    }
};
