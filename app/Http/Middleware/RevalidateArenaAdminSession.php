<?php

namespace App\Http\Middleware;

use App\Models\AdminAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La sesion del panel vale mientras su cuenta exista y siga activa.
 *
 * El login ya comprobaba cuenta activa y contraseña, pero despues bastaba con
 * que la sesion dijera "autenticado": si se desactivaba o borraba una cuenta
 * de admin, sus sesiones abiertas seguian valiendo hasta caducar. Esto lo
 * revisa en cada peticion (solo cuando la sesion es de admin, asi que a los
 * jugadores no les cuesta nada) y, si la cuenta ya no vale, cierra la sesion
 * del panel. Va en todo el grupo web, no solo en el panel, porque el flag se
 * lee tambien fuera de el (la barra de navegacion, las capturas de abandono).
 */
class RevalidateArenaAdminSession
{
    public const SESSION_KEYS = [
        'arena_admin.authenticated',
        'arena_admin.account_id',
        'arena_admin.username',
        'arena_admin.display_name',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->get('arena_admin.authenticated') === true) {
            $id = $request->session()->get('arena_admin.account_id');

            $valida = $id !== null
                && AdminAccount::query()->whereKey($id)->where('is_active', true)->exists();

            if (!$valida) {
                $request->session()->forget(self::SESSION_KEYS);
            }
        }

        return $next($request);
    }
}
