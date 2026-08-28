<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_accounts')) {
            return;
        }

        $username = trim((string) config('arena_admin.bootstrap_username', 'admin'));
        $displayName = trim((string) config('arena_admin.bootstrap_display_name', $username));
        $password = (string) config('arena_admin.bootstrap_password', '');

        if ($username === '' || $password === '') {
            return;
        }

        DB::table('admin_accounts')->updateOrInsert(
            ['username' => $username],
            [
                'password_hash' => Hash::make($password),
                'display_name' => $displayName !== '' ? $displayName : $username,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        // Intentionally left blank. This migration only syncs credentials from env.
    }
};
