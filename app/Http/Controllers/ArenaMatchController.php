<?php

namespace App\Http\Controllers;

use App\Models\ArenaMatch;
use App\Models\MatchAbandonmentReport;
use App\Models\MatchReport;
use App\Models\Queue;
use App\Services\ArenaAbandonmentService;
use App\Services\ArenaMatchResultService;
use App\Services\ArenaMatchmakingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
            return redirect()->route('lobby')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $userPlayerIds = Auth::user()->players()->pluck('id')->all();

        $activeMatches = $this->constrainMatchesToPlayers(
            ArenaMatch::query()
                ->with('report')
                ->whereNotIn('status', ['completed', 'cancelled', 'void', 'disputed', 'abandoned']),
            $userPlayerIds
        )
            ->latest('created_at')
            ->get();

        // Todos los que llegaron a jugarse. Los cruces que nunca empezaron ya
        // no estan aqui porque no existen: se borran al cancelarse. Asi que un
        // 'cancelled' que sobreviva es lo que ahora significa -un combate
        // interrumpido por alguien de fuera- y eso si se peleo, con lo que
        // ocultarlo hacia desaparecer del historial de los cuatro una partida
        // que jugaron.
        $completedMatches = $this->constrainMatchesToPlayers(
            ArenaMatch::query()
                ->with(['report', 'results'])
                ->whereIn('status', ['completed', 'void', 'disputed', 'abandoned', 'cancelled']),
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
            return redirect()->route('lobby')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $match->load([
            'report.reporter',
            'report.confirmer',
            'report.rejector',
            'report.reviewer',
            'results.player',
            'abandonmentReports.accused',
            'abandonmentReports.reporter',
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
            return redirect()->route('lobby')
                ->withErrors(['error' => 'La tabla matches aun no tiene el esquema MVP v1 en produccion.']);
        }

        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'player_id' => 'required|exists:players,id',
            // Desde donde se acepto. El aviso de cruce vive encima de la cola,
            // asi que devolver alli deja al jugador donde estaba, viendo quien
            // falta por confirmar, en vez de saltarle a otra pagina.
            'from' => 'nullable|in:queue',
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

            return redirect()->route('lobby', ['mode' => $match->arena_mode])
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

        if ($request->input('from') === 'queue') {
            return redirect()->route('lobby', ['mode' => $match->arena_mode])
                ->with('success', '¡Combate aceptado! Esperando a los demás.');
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
            return redirect()->route('lobby')
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

        return redirect()->route('lobby', ['mode' => $match->arena_mode])
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

        // De vuelta al lobby, que es donde ocurre el combate entero. Mandar a
        // la pagina del enfrentamiento sacaba al jugador del flujo justo en el
        // paso que lo cierra.
        return redirect()->route('lobby', ['mode' => $match->arena_mode])
            ->with('success', 'Reporte enviado con las capturas. Falta que el rival lo confirme para que el ladder lo cuente.');
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

        return redirect()->route('lobby', ['mode' => $report->match->arena_mode])
            ->with('success', 'Resultado confirmado. El ladder ya reparte los puntos de este enfrentamiento.');
    }

    public function rejectReport(Request $request, ArenaMatchResultService $resultService)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $request->validate([
            'report_id' => 'required|exists:match_reports,id',
            'player_id' => 'required|exists:players,id',
            'rejection_note' => 'required|string|min:5|max:1000',
            'rejection_files' => 'nullable|array|max:3',
            'rejection_files.*' => 'file|mimes:jpg,jpeg,png,webp,gif,bmp,avif,heic,heif|max:10240',
        ], [
            'rejection_note.required' => 'Explica por que lo rechazas: sin motivo moderacion no tiene por donde empezar.',
            'rejection_note.min' => 'Cuenta un poco mas: con dos palabras moderacion no puede decidir nada.',
            'rejection_files.max' => 'Solo puedes subir hasta 3 capturas con el rechazo.',
            'rejection_files.*.mimes' => 'Las capturas deben ser JPG, PNG, WEBP, GIF, BMP, AVIF o HEIC.',
            'rejection_files.*.max' => 'Cada captura no puede superar los 10 MB.',
        ]);

        $report = MatchReport::with('match')->findOrFail($request->report_id);
        $player = Auth::user()->players()->findOrFail((int) $request->player_id);

        try {
            $resultService->rejectReport(
                $report,
                $player,
                $request->rejection_note,
                $request->file('rejection_files', [])
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // ANTES que RuntimeException, del que hereda. Sin esto un fallo de
            // base de datos entraba por la rama de "regla del juego" y le
            // enseñaba al jugador la consulta entera, con nombres de tablas y
            // todo, en mitad del panel de combate.
            Log::error('Fallo de base de datos al rechazar un reporte', [
                'report_id' => $report->id,
                'player_id' => $player->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors([
                'error' => 'No se pudo registrar tu rechazo por un problema del servidor. Avisa en el Discord con la hora exacta.',
            ]);
        } catch (\RuntimeException $e) {
            // Las de regla -"ya no esta esperando confirmacion", "solo el rival
            // puede rechazar"- se le cuentan al jugador tal cual.
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Lo demas es un fallo nuestro. Antes se le enseñaba al jugador el
            // mensaje crudo, que con un error de base de datos es la consulta
            // entera, y si no se fijaba en el aviso de arriba parecia que el
            // boton no hacia nada.
            Log::error('No se pudo registrar el rechazo del reporte', [
                'report_id' => $report->id,
                'player_id' => $player->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors([
                'error' => 'No se pudo registrar tu rechazo. Vuelve a intentarlo; si sigue fallando, avisa en el Discord.',
            ]);
        }

        return redirect()->route('lobby', ['mode' => $report->match->arena_mode])
            ->with('warning', 'Reporte rechazado. El enfrentamiento pasa a disputa y lo revisa moderacion.');
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

    /**
     * Un jugador avisa de que alguien se fue del combate.
     *
     * No sanciona a nadie: manda el enfrentamiento a disputa y lo deja en manos
     * de moderacion. Se puede señalar a un rival o al propio compañero.
     */
    public function reportAbandonment(Request $request, ArenaAbandonmentService $abandonmentService)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'player_id' => 'required|exists:players,id',
            'accused_player_id' => 'required|exists:players,id',
            'note' => 'required|string|min:5|max:500',
            'files' => 'nullable|array|max:3',
            'files.*' => 'image|max:5120',
        ], [
            'note.required' => 'Explica que paso: sin motivo no hay nada que revisar.',
            'note.min' => 'Escribe algo mas de detalle sobre el abandono.',
            'files.*.image' => 'Las pruebas tienen que ser imagenes.',
            'files.*.max' => 'Cada captura debe pesar menos de 5 MB.',
        ]);

        $match = ArenaMatch::findOrFail($request->match_id);
        $player = Auth::user()->players()->findOrFail($request->player_id);

        try {
            $abandonmentService->report(
                $match,
                $player,
                (int) $request->accused_player_id,
                $request->note,
                $request->file('files', [])
            );
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Fallo de base de datos al reportar un abandono', [
                'match_id' => $match->id,
                'player_id' => $player->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors([
                'error' => 'No se pudo registrar el aviso por un problema del servidor. Avisa en el Discord con la hora exacta.',
            ]);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Fallo inesperado al reportar un abandono', [
                'match_id' => $match->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'No se pudo registrar el aviso. Intentalo de nuevo.']);
        }

        return back()->with('success', 'Aviso enviado. Un administrador revisara el abandono.');
    }

    public function abandonmentEvidence(MatchAbandonmentReport $abandonment, string $slot)
    {
        if (!Auth::check()) {
            return redirect()->route('auth.discord');
        }

        $match = $abandonment->match()->firstOrFail();
        $user = Auth::user();

        if (!$user->isAdmin()) {
            $mios = $match->getAllPlayers()
                ->pluck('player_id')
                ->map(fn ($id) => (int) $id)
                ->intersect($user->players()->pluck('id')->map(fn ($id) => (int) $id));

            // Ser participante no basta mientras el combate sigue abierto: la
            // captura de un aviso es de la pelea EN CURSO, y darsela al bando
            // contrario es regalarle la pantalla del enemigo.
            $puede = $mios->isNotEmpty()
                && $mios->contains(fn (int $id) => $abandonment->visibleParaJugador($id, $match));

            if (!$puede) {
                abort(403, 'No tienes acceso a esta evidencia.');
            }
        }

        $path = $abandonment->evidencePath($slot);
        $disk = Storage::disk(MatchAbandonmentReport::EVIDENCE_DISK);

        if (!$path || !$disk->exists($path)) {
            abort(404, 'La evidencia solicitada no existe en el servidor.');
        }

        return $disk->response(
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
