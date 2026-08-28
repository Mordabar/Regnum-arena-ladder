<?php

namespace App\Http\Controllers;

use App\Models\AdminAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    public function entry(Request $request)
    {
        if ($this->isAuthenticated($request)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login', [
            'adminPath' => trim((string) config('arena_admin.path', 'lowly-control-room'), '/'),
        ]);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string|max:255',
        ]);

        if (!Schema::hasTable('admin_accounts')) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors([
                    'error' => 'El panel admin aun no esta instalado en esta base de datos. Sube la migracion `2026_03_30_000004_create_admin_accounts_table.php` y ejecuta `php artisan migrate --force`.',
                ]);
        }

        $limiterKey = $this->limiterKey($request, $validated['username']);
        $maxAttempts = (int) config('arena_admin.max_attempts', 5);
        $decaySeconds = (int) config('arena_admin.decay_seconds', 300);

        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($limiterKey);

            return back()
                ->withInput($request->except('password'))
                ->withErrors([
                    'error' => 'Demasiados intentos. Espera ' . $seconds . ' segundos antes de volver a intentar.',
                ]);
        }

        $account = AdminAccount::query()
            ->where('username', $validated['username'])
            ->where('is_active', true)
            ->first();

        if (!$account || !Hash::check($validated['password'], $account->password_hash)) {
            RateLimiter::hit($limiterKey, $decaySeconds);

            return back()
                ->withInput($request->except('password'))
                ->withErrors(['error' => 'Credenciales de administrador invalidas.']);
        }

        RateLimiter::clear($limiterKey);

        $request->session()->regenerate();
        $request->session()->put([
            'arena_admin.authenticated' => true,
            'arena_admin.account_id' => $account->id,
            'arena_admin.username' => $account->username,
            'arena_admin.display_name' => $account->display_name ?: $account->username,
        ]);

        $account->update([
            'last_login_at' => now(),
        ]);

        return redirect()->route('admin.dashboard')
            ->with('success', 'Sesion administrativa iniciada.');
    }

    public function logout(Request $request)
    {
        $request->session()->forget([
            'arena_admin.authenticated',
            'arena_admin.account_id',
            'arena_admin.username',
            'arena_admin.display_name',
        ]);
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('success', 'Sesion administrativa cerrada.');
    }

    private function isAuthenticated(Request $request): bool
    {
        return $request->session()->get('arena_admin.authenticated') === true;
    }

    private function limiterKey(Request $request, string $username): string
    {
        return 'arena-admin-login|' . Str::lower($username) . '|' . $request->ip();
    }
}
