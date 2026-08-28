<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_accounts')) {
            Schema::create('admin_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64)->unique();
                $table->string('password_hash');
                $table->string('display_name', 120)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();
            });
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
        Schema::dropIfExists('admin_accounts');
    }
};
