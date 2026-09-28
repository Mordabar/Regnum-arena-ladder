<?php

namespace App\Services\Discord;

use App\Models\AppSetting;
use App\Models\ArenaMatch;
use App\Models\Player;
use App\Models\Queue;
use App\Services\DiscordBotService;
use App\Services\TestingLabService;
use App\Support\ArenaMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anuncios de actividad en un canal publico del servidor de Discord.
 *
 * Son para dar ambiente y avisar de que hay con quien jugar: "hay alguien
 * esperando rival en 1v1", "arranca un 2v2 en la zona 5", "ahora mismo hay
 * tres en cola". Nunca nombran a nadie ni sustituyen a los avisos
 * personales (DM y push), que son los que le dicen a cada jugador lo suyo.
 *
 * Todo esta pensado para no hacer ruido:
 * - sin canal configurado (DISCORD_ANNOUNCEMENTS_CHANNEL_ID) no hace nada;
 * - el panel puede apagarlos sin tocar el servidor;
 * - cada tipo tiene su limite de frecuencia;
 * - los bots del laboratorio no cuentan ni disparan nada;
 * - sale despues de responder, como el resto de Discord.
 */
class ActivityAnnouncer
{
    public const SETTING_ENABLED = 'discord_announcements_enabled';

    /** Dorado de la marca. */
    private const COLOR = 0xD8B15C;

    public function __construct(
        private readonly DiscordBotService $discord,
        private readonly TestingLabService $lab,
    ) {
    }

    public function channelId(): string
    {
        return trim((string) config('services.discord.announcements.channel_id', ''));
    }

    /** Si hay algo que hacer: bot, canal y el interruptor del panel. */
    public function enabled(): bool
    {
        return $this->discord->isConfigured()
            && $this->channelId() !== ''
            && (bool) AppSetting::getValue(self::SETTING_ENABLED, true);
    }

    /**
     * Alguien entra en cola. Se anuncia cuando la cola de esa modalidad
     * estaba vacia: es cuando el aviso sirve ("hay alguien esperando"). Con
     * gente ya esperando, el anuncio anterior sigue siendo cierto.
     */
    public function queueJoined(Queue $queue): void
    {
        if (!$this->enabled()) {
            return;
        }

        // La decision se toma despues de responder, con la cola releida: si
        // el mismo jugador ya salio emparejado en esta peticion, no hay nadie
        // esperando y el anuncio seria mentira.
        $id = $queue->getKey();
        $this->later(function () use ($id) {
            $queue = Queue::query()->find($id);

            if ($queue) {
                $this->announceQueue($queue);
            }
        });
    }

    private function announceQueue(Queue $queue): void
    {
        if ($queue->status !== 'waiting' || $this->isLabPlayer((int) $queue->player_id)) {
            return;
        }

        $mode = ArenaMode::resolve($queue->arena_mode);

        // Se anuncia cuando no habia nadie mas esperando: la entrada que abre
        // la cola. Un grupo entra con varias filas a la vez (una por
        // miembro, mismo team_id), asi que se descuenta el grupo entero y no
        // solo esta fila; si no, una party nunca se anunciaba. Cada fila del
        // grupo llega aqui, y el limite de frecuencia deja pasar solo una.
        if ($this->waitingOutsideGroup($queue, $mode) > 0) {
            return;
        }

        if (!$this->throttle('queue:' . $mode, 'queue_every_minutes')) {
            return;
        }

        $realm = (string) Player::query()->whereKey($queue->player_id)->value('realm');
        $realmName = Player::REALMS[$realm] ?? ucfirst($realm);
        $others = collect(Player::REALMS)->except($realm)->values()->implode(' y ');
        $quien = $queue->team_id ? 'Un grupo' : 'Un guerrero';

        $this->post(
            '⚔️ ' . ArenaMode::displayName($mode) . ': hay alguien esperando rival',
            "{$quien} de **{$realmName}** acaba de entrar en cola. {$others}: es vuestro momento.",
        );
    }

    /** Cuantos esperan en la modalidad sin contar el grupo de esta fila. */
    private function waitingOutsideGroup(Queue $queue, string $mode): int
    {
        return Queue::query()
            ->where('status', 'waiting')
            ->where('arena_mode', $mode)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereNotIn('player_id', $this->lab->testPlayersQuery()->select('players.id'))
            ->when(
                $queue->team_id,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('team_id')->orWhere('team_id', '!=', $queue->team_id)),
                fn ($q) => $q->whereKeyNot($queue->getKey())
            )
            ->count();
    }

    /** Un combate arranca (todos aceptaron). */
    public function matchStarted(ArenaMatch $match): void
    {
        if (!$this->enabled()) {
            return;
        }

        $this->later(fn () => $this->announceMatch($match));
    }

    private function announceMatch(ArenaMatch $match): void
    {
        if ($this->isLabMatch($match)) {
            return;
        }

        if (!$this->throttle('match', 'match_every_minutes')) {
            return;
        }

        $a = ArenaMatch::REALMS[$match->team_a_realm] ?? ucfirst((string) $match->team_a_realm);
        $b = ArenaMatch::REALMS[$match->team_b_realm] ?? ucfirst((string) $match->team_b_realm);

        // Sin la zona a proposito: es un mundo abierto, y anunciar en un canal
        // publico donde se esta peleando ahora mismo invitaria a terceros a
        // meterse en el combate.
        $this->post(
            '🔥 Arranca un ' . ArenaMode::label($match->arena_mode),
            "**{$a}** contra **{$b}**. La arena está viva: entra y busca el tuyo.",
        );
    }

    /**
     * El resumen de actividad que lanza el cron. Solo si hay movimiento, y
     * como mucho una vez por periodo.
     */
    public function pulse(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $byMode = [];
        foreach (ArenaMode::enabled() as $mode) {
            $byMode[$mode] = array_sum($this->waitingByRealm($mode));
        }

        $waiting = array_sum($byMode);
        $inProgress = $this->matchesInProgress();

        if ($waiting === 0 && $inProgress === 0) {
            return;
        }

        if (!$this->throttle('pulse', 'pulse_every_minutes')) {
            return;
        }

        $parts = collect($byMode)->filter()->map(fn (int $n, string $m) => $m . ': ' . $n)->implode(' · ');
        $lines = [];
        if ($waiting > 0) {
            $lines[] = "**{$waiting}** " . ($waiting === 1 ? 'guerrero esperando' : 'guerreros esperando') . " rival ({$parts})";
        }
        if ($inProgress > 0) {
            $lines[] = "**{$inProgress}** " . ($inProgress === 1 ? 'combate en marcha' : 'combates en marcha');
        }

        $this->post('📊 Ahora mismo en la arena', implode("\n", $lines));
    }

    /** Un mensaje de prueba para comprobar que el bot llega al canal. */
    public function test(): bool
    {
        if (!$this->discord->isConfigured() || $this->channelId() === '') {
            return false;
        }

        $this->post('✅ Arena Ladder conectado', 'Los anuncios de actividad saldrán en este canal.');

        return true;
    }

    /**
     * Despues de guardar y de responder. Desde el cron, en el acto.
     */
    private function later(\Closure $trabajo): void
    {
        $seguro = function () use ($trabajo) {
            try {
                $trabajo();
            } catch (\Throwable $e) {
                Log::warning('No se pudo preparar un anuncio de Discord', ['error' => $e->getMessage()]);
            }
        };

        DB::afterCommit(function () use ($seguro) {
            if (app()->runningInConsole() && !app()->runningUnitTests()) {
                $seguro();

                return;
            }

            app()->terminating($seguro);
        });
    }

    private function post(string $title, string $description): void
    {
        try {
            $this->discord->publicarEnCanal($this->channelId(), [
                'embeds' => [[
                    'title' => $title,
                    'description' => $description,
                    'color' => self::COLOR,
                    'url' => url('/lobby'),
                    'footer' => ['text' => 'Regnum Arena Ladder · regnumarenaladder.top'],
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo programar un anuncio de Discord', ['error' => $e->getMessage()]);
        }
    }

    /**
     * true si toca anunciar; false si ya se anuncio hace poco. Cache::add es
     * atomico: dos peticiones a la vez no anuncian dos veces.
     */
    private function throttle(string $key, string $configKey): bool
    {
        $minutes = max(1, (int) config('services.discord.announcements.' . $configKey, 15));

        return Cache::add('discord:anuncio:' . $key, true, now()->addMinutes($minutes));
    }

    /**
     * Gente esperando en una modalidad, por reino, sin contar los bots.
     *
     * @return array<string, int>
     */
    private function waitingByRealm(string $mode): array
    {
        return Queue::query()
            ->join('players', 'players.id', '=', 'queues.player_id')
            ->where('queues.status', 'waiting')
            ->where('queues.arena_mode', $mode)
            ->where(fn ($q) => $q->whereNull('queues.expires_at')->orWhere('queues.expires_at', '>', now()))
            ->whereNotIn('queues.player_id', $this->lab->testPlayersQuery()->select('players.id'))
            ->groupBy('players.realm')
            ->selectRaw('players.realm, count(*) as total')
            ->pluck('total', 'realm')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function matchesInProgress(): int
    {
        $labIds = $this->lab->testPlayerIds();

        return ArenaMatch::query()
            ->where('status', 'in_progress')
            ->get(['id', 'team_a', 'team_b'])
            ->reject(fn (ArenaMatch $m) => $labIds->isNotEmpty() && $this->lab->matchIntersectsPlayerPool($m, $labIds))
            ->count();
    }

    private function isLabPlayer(int $playerId): bool
    {
        return $this->lab->testPlayersQuery()->whereKey($playerId)->exists();
    }

    private function isLabMatch(ArenaMatch $match): bool
    {
        $labIds = $this->lab->testPlayerIds();

        return $labIds->isNotEmpty() && $this->lab->matchIntersectsPlayerPool($match, $labIds);
    }
}
