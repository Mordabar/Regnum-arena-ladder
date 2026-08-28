<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureArenaAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('arena_admin.authenticated') === true) {
            return $next($request);
        }

        return redirect()->route('admin.login');
    }
}
