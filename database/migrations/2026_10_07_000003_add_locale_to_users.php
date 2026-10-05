<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** El idioma de cada usuario: el de sus mensajes directos de Discord. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || Schema::hasColumn('users', 'locale')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'locale')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        }
    }
};
