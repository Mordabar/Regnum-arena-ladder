<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ArenaMatchController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LadderController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\QueueHubController;
use Illuminate\Support\Facades\Route;

$arenaAdminPath = trim((string) config('arena_admin.path', 'lowly-control-room'), '/');

Route::get('/', function () {
    return view('home_v2');
})->name('home');

Route::get('/login', function () {
    return redirect()->route('auth.discord');
})->name('login');

Route::get('/auth/discord', [AuthController::class, 'redirectToDiscord'])->name('auth.discord');
Route::get('/auth/discord/callback', [AuthController::class, 'handleDiscordCallback'])->name('auth.discord.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/ladder', [LadderController::class, 'index'])->name('ladder.index');
Route::get('/ladder/player/{player}', [LadderController::class, 'show'])->name('ladder.show');

Route::get('/player/crear', [PlayerController::class, 'create'])->middleware('auth')->name('player.create');
Route::post('/player/register', [PlayerController::class, 'store'])->name('player.register');
Route::put('/player/{player}/update', [PlayerController::class, 'update'])->name('player.update');
Route::delete('/player/{player}', [PlayerController::class, 'destroy'])->name('player.destroy');

Route::middleware(['auth', 'arena.maintenance'])->group(function () {
    // El lobby y la arena eran dos paginas que ensenaban lo mismo, y desde el
    // lobby "Pelear" llevaba a la otra en vez de a la cola. Ahora son una: se
    // elige guerrero y se entra a combatir en la misma pantalla.
    Route::get('/lobby', [QueueHubController::class, 'index'])->name('lobby');

    // /queue sigue existiendo por los enlaces viejos, pero solo apunta al lobby.
    Route::get('/queue', function (\Illuminate\Http\Request $request) {
        return redirect()->route('lobby', array_filter(['mode' => $request->query('mode')]));
    })->name('queue.index');
    Route::get('/matches', [ArenaMatchController::class, 'index'])->name('matches.index');
    Route::get('/matches/{match}', [ArenaMatchController::class, 'show'])
        ->whereNumber('match')
        ->name('matches.show');
});

Route::middleware('arena.maintenance')->group(function () {
    Route::get('/queue/state-poll', [QueueHubController::class, 'statePoll'])
        ->middleware('throttle:30,1')
        ->name('queue.state-poll');
});

Route::middleware('auth')->group(function () {
    Route::get('/queue/premade/candidates', [QueueHubController::class, 'premadeCandidates'])->name('queue.premade.candidates');
    Route::post('/queue/join', [QueueHubController::class, 'join'])->name('queue.join');
    Route::post('/queue/leave', [QueueHubController::class, 'leave'])->name('queue.leave');
    Route::post('/party/create', [QueueHubController::class, 'createParty'])->name('party.create');
    Route::post('/party/{party}/invite/{member}/accept', [QueueHubController::class, 'acceptPartyInvite'])->name('party.accept');
    Route::post('/party/{party}/invite/{member}/reject', [QueueHubController::class, 'rejectPartyInvite'])->name('party.reject');
    Route::post('/party/{party}/leave', [QueueHubController::class, 'leaveParty'])->name('party.leave');
    Route::post('/party/{party}/enqueue', [QueueHubController::class, 'enqueueParty'])->name('party.enqueue');
    Route::post('/matches/accept', [ArenaMatchController::class, 'accept'])->name('matches.accept');
    Route::post('/matches/reject', [ArenaMatchController::class, 'reject'])->name('matches.reject');
    Route::post('/matches/report', [ArenaMatchController::class, 'report'])->name('matches.report');
    Route::post('/matches/report/confirm', [ArenaMatchController::class, 'confirmReport'])->name('matches.report.confirm');
    Route::post('/matches/report/reject', [ArenaMatchController::class, 'rejectReport'])->name('matches.report.reject');
    Route::get('/matches/report/{report}/evidence/{slot}', [ArenaMatchController::class, 'evidence'])->name('matches.report.evidence');
});

Route::prefix('/' . $arenaAdminPath)->group(function () {
    Route::get('/', [AdminAuthController::class, 'entry'])->name('admin.login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('admin.login.attempt');

    Route::middleware('arena.admin')->name('admin.')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::post('/operations/process-queue', [AdminController::class, 'processQueue'])->name('operations.process-queue');
        Route::post('/operations/expire-pending', [AdminController::class, 'expirePendingAcceptance'])->name('operations.expire-pending');

        Route::post('/testing/seed', [QueueHubController::class, 'sandboxSeed'])->name('testing.seed');
        Route::post('/testing/toggle-bot', [QueueHubController::class, 'sandboxToggleBot'])->name('testing.toggle-bot');
        Route::post('/testing/enqueue-realm', [QueueHubController::class, 'sandboxEnqueueRealm'])->name('testing.enqueue-realm');
        Route::post('/testing/process', [QueueHubController::class, 'sandboxProcess'])->name('testing.process');
        Route::post('/testing/accept', [QueueHubController::class, 'sandboxAccept'])->name('testing.accept');
        Route::post('/testing/accept-parties', [QueueHubController::class, 'sandboxAcceptParties'])->name('testing.accept-parties');
        Route::post('/testing/invite-me', [QueueHubController::class, 'sandboxInviteMe'])->name('testing.invite-me');
        Route::post('/testing/resolve-all', [QueueHubController::class, 'sandboxResolveAll'])->name('testing.resolve-all');
        Route::post('/testing/resolve/{match}', [QueueHubController::class, 'sandboxResolve'])->name('testing.resolve');
        Route::post('/testing/bot-report/{match}', [QueueHubController::class, 'sandboxBotReport'])->name('testing.bot-report');
        Route::post('/testing/reset', [QueueHubController::class, 'sandboxReset'])->name('testing.reset');
        Route::post('/testing/destroy', [QueueHubController::class, 'sandboxDestroy'])->name('testing.destroy');

        Route::post('/matches/{match}/resolve', [AdminController::class, 'resolveMatch'])->name('matches.resolve');

        Route::get('/players', [AdminController::class, 'players'])->name('players.index');
        Route::post('/players/create', [AdminController::class, 'storePlayer'])->name('players.store');
        Route::post('/players/{player}', [AdminController::class, 'updatePlayer'])->name('players.update');
        Route::delete('/players/{player}', [AdminController::class, 'destroyPlayer'])->name('players.destroy');

        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');

        Route::get('/zonas', [AdminController::class, 'zones'])->name('zones');
        Route::post('/zonas', [AdminController::class, 'saveZones'])->name('zones.save');

        Route::middleware('arena.maintenance')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
            Route::get('/inbox', [AdminController::class, 'moderationInbox'])->name('inbox');
            Route::get('/testing', [QueueHubController::class, 'sandbox'])->name('testing');
            Route::get('/matches', [AdminController::class, 'matches'])->name('matches.index');
            Route::get('/matches/{match}', [AdminController::class, 'showMatch'])
                ->whereNumber('match')
                ->name('matches.show');
        });
    });
});




