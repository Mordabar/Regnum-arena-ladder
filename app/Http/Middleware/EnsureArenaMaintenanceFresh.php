<?php

namespace App\Http\Middleware;

use App\Services\ArenaMaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureArenaMaintenanceFresh
{
    public function __construct(
        private readonly ArenaMaintenanceService $maintenanceService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRunFallback($request)) {
            try {
                $this->maintenanceService->runTick();
            } catch (\Throwable $exception) {
                Log::warning('Arena maintenance web fallback failed.', [
                    'route' => $request->route()?->getName(),
                    'path' => $request->path(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $next($request);
    }

    private function shouldRunFallback(Request $request): bool
    {
        if ($request->user() !== null) {
            return true;
        }

        return $request->session()->get('arena_admin.authenticated') === true;
    }
}
