<?php

namespace App\Http\Controllers;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Queue;
use App\Services\ArenaMatchResultService;
use App\Services\ArenaMatchmakingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArenaMatchController extends Controller
{
    public function index()
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $matchmakingService = app(ArenaMatchmakingService::class);

        if (!$matchmakingService->isMatchesSchemaReady()) {
            return redirect()->route('queue.index')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $userPlayerIds = Auth::user()->players()->pluck('id')->all();

        $activeMatches = $this->constrainMatchesToPlayers(
            ArenaMatch::query()
                ->with('report')
                ->whereNotIn('status', ['completed', 'cancelled', 'void', 'disputed']),
            $userPlayerIds
        )
            ->latest('created_at')
            ->get();

        $completedMatches = $this->constrainMatchesToPlayers(
            ArenaMatch::query()
                ->with(['report', 'results'])
                ->whereIn('status', ['completed', 'cancelled', 'void', 'disputed']),
            $userPlayerIds
        )
            ->latest('created_at')
            ->take(10)
            ->get();

        return view('matches.index_v3', compact('activeMatches', 'completedMatches'));
    }

    public function show(ArenaMatch $match)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $matchmakingService = app(ArenaMatchmakingService::class);

        if (!$matchmakingService->isMatchesSchemaReady()) {
            return redirect()->route('queue.index')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $match->load([
            'report.reporter',
            'report.confirmer',
            'report.rejector',
            'report.reviewer',
            'results.player',
        ]);

        $userPlayerIds = Auth::user()->players()->pluck('id');
        $matchPlayerIds = $match->getAllPlayers()->pluck('player_id');

        if (!$userPlayerIds->intersect($matchPlayerIds)->count()) {
            abort(403, 'No tienes acceso a este match.');
        }

        // Preload all match queues in a single query to avoid N+1 in the view
        $teamQueues = Queue::query()
            ->where('match_id', (string) $match->id)
            ->whereIn('status', ['matched', 'accepted'])
            ->get()
            ->keyBy('player_id');

        return view('matches.show_v3', compact('match', 'teamQueues'));
    }

    public function accept(Request $request, ArenaMatchResultService $resultService)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $matchmakingService = app(ArenaMatchmakingService::class);

        if (!$matchmakingService->isMatchesSchemaReady()) {
            return redirect()->route('queue.index')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'player_id' => 'required|exists:players,id',
        ]);

        $match = ArenaMatch::findOrFail($request->match_id);
        $playerId = (int) $request->player_id;
        $player = Auth::user()->players()->findOrFail($playerId);

        $playerInMatch = $match->getAllPlayers()->firstWhere('player_id', $playerId);
        if (!$playerInMatch) {
            return back()->withErrors(['error' => 'No estás en este match.']);
        }

        if (!$match->isPendingAcceptance()) {
            return back()->withErrors(['error' => 'Este match ya no está disponible para aceptar.']);
        }

        if ($match->isExpired()) {
            app(ArenaMatchmakingService::class)->cancelMatch($match, 'timeout', null, true);

            return redirect()->route('queue.index', ['mode' => $match->arena_mode])
                ->withErrors(['error' => 'El tiempo para aceptar este match expiró.']);
        }

        try {
            DB::transaction(function () use ($match, $player, $resultService) {
                $queue = $player->queues()
                    ->where('match_id', (string) $match->id)
                    ->where('status', 'matched')
                    ->latest('id')
                    ->first();

                if (!$queue) {
                    throw new \RuntimeException('La cola de este match ya no está disponible.');
                }

                $queue->update(['status' => 'accepted']);
                $resultService->promoteMatchToInProgressIfReady($match->fresh());
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('matches.show', $match)
            ->with('success', '¡Match aceptado! Esperando a los demás jugadores...');
    }

    public function reject(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $matchmakingService = app(ArenaMatchmakingService::class);

        if (!$matchmakingService->isMatchesSchemaReady()) {
            return redirect()->route('queue.index')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'player_id' => 'required|exists:players,id',
        ]);

        $match = ArenaMatch::findOrFail($request->match_id);
        $playerId = (int) $request->player_id;
        $player = Auth::user()->players()->findOrFail($playerId);

        $playerInMatch = $match->getAllPlayers()->firstWhere('player_id', $playerId);
        if (!$playerInMatch) {
            return back()->withErrors(['error' => 'No estás en este match.']);
        }

        // Rechazar solo tiene sentido mientras el match espera aceptaciones.
        // Sin esta comprobacion, quien fuera perdiendo un match ya empezado (o
        // en disputa) podia cancelarlo desde aqui y evitar la derrota.
        if (!$match->isPendingAcceptance()) {
            return back()->withErrors([
                'error' => 'Este match ya está en curso: no se puede rechazar. Si hubo un problema, repórtalo para que lo revise un administrador.',
            ]);
        }

        app(ArenaMatchmakingService::class)->cancelMatch($match, 'player_rejected', $player->id, true);

        return redirect()->route('queue.index', ['mode' => $match->arena_mode])
            ->with('warning', 'Match rechazado. Los demás jugadores fueron reencolados.');
    }

    public function report(Request $request, ArenaMatchResultService $resultService)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'player_id' => 'required|exists:players,id',
            'claimed_winner_team' => 'required|in:team_a,team_b,draw',
            'evidence_files' => 'required|array|min:1|max:3',
            'evidence_files.*' => 'required|file|mimes:jpg,jpeg,png,webp,gif,bmp,avif,heic,heif|max:10240',
            'reporter_note' => 'nullable|string|max:1000',
        ], [
            'evidence_files.required' => 'Debes subir al menos una captura del combate final.',
            'evidence_files.array' => 'Las capturas del combate no llegaron en un formato valido.',
            'evidence_files.min' => 'Debes subir al menos una captura del combate final.',
            'evidence_files.max' => 'Solo puedes subir hasta 3 capturas por reporte.',
            'evidence_files.*.required' => 'Cada captura adjunta debe ser un archivo valido.',
            'evidence_files.*.mimes' => 'Las capturas deben ser JPG, PNG, WEBP, GIF, BMP, AVIF o HEIC.',
            'evidence_files.*.max' => 'Cada captura no puede superar los 10 MB.',
        ]);

        $match = ArenaMatch::with('report')->findOrFail($request->match_id);
        $player = Auth::user()->players()->findOrFail((int) $request->player_id);

        try {
            $resultService->submitReport($match, $player, [
                'claimed_winner_team' => $request->claimed_winner_team,
                'evidence_files' => $request->file('evidence_files', []),
                'reporter_note' => $request->reporter_note,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('matches.show', $match)
            ->with('success', 'Reporte enviado. El equipo rival ya puede confirmarlo o rechazarlo.');
    }

    public function confirmReport(Request $request, ArenaMatchResultService $resultService)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $request->validate([
            'report_id' => 'required|exists:match_reports,id',
            'player_id' => 'required|exists:players,id',
        ]);

        $report = MatchReport::with('match')->findOrFail($request->report_id);
        $player = Auth::user()->players()->findOrFail((int) $request->player_id);

        try {
            $resultService->confirmReport($report, $player);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('matches.show', $report->match)
            ->with('success', 'Reporte confirmado. El ladder ya fue actualizado.');
    }

    public function rejectReport(Request $request, ArenaMatchResultService $resultService)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $request->validate([
            'report_id' => 'required|exists:match_reports,id',
            'player_id' => 'required|exists:players,id',
            'rejection_note' => 'nullable|string|max:1000',
        ]);

        $report = MatchReport::with('match')->findOrFail($request->report_id);
        $player = Auth::user()->players()->findOrFail((int) $request->player_id);

        try {
            $resultService->rejectReport($report, $player, $request->rejection_note);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->route('matches.show', $report->match)
            ->with('warning', 'Reporte rechazado. El match paso a disputa.');
    }

    public function evidence(MatchReport $report, string $slot)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $match = $report->match()->firstOrFail();
        $user = Auth::user();
        $userPlayerIds = $user->players()->pluck('id');
        $canAccess = $user->isAdmin()
            || $match->getAllPlayers()
                ->pluck('player_id')
                ->intersect($userPlayerIds)
                ->isNotEmpty();

        if (!$canAccess) {
            abort(403, 'No tienes acceso a esta evidencia.');
        }

        $path = $report->evidencePath($slot);
        $diskName = $report->resolveEvidenceDisk($slot);

        if (!$path || !$diskName || !Storage::disk($diskName)->exists($path)) {
            abort(404, 'La evidencia solicitada no existe en el servidor.');
        }

        return Storage::disk($diskName)->response(
            $path,
            basename($path),
            ['Content-Disposition' => 'inline; filename="' . basename($path) . '"']
        );
    }

    private function constrainMatchesToPlayers(Builder $query, array $userPlayerIds): Builder
    {
        return $query->whereExists(function ($queueQuery) use ($userPlayerIds) {
            $queueQuery->selectRaw('1')
                ->from('queues')
                ->whereColumn('queues.match_id', 'matches.id')
                ->whereIn('queues.player_id', $userPlayerIds);
        });
    }

}
