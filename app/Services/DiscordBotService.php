<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use App\Support\ArenaMode;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordBotService
{
    private string $botToken;
    private string $baseUrl = 'https://discord.com/api/v10';

    private const DM_CACHE_PREFIX = 'discord:dm:';

    public function __construct()
    {
        // Sin cast, un DISCORD_BOT_TOKEN ausente hacia que el contenedor no
        // pudiera construir este servicio y tumbaba cualquier pagina que lo
        // inyecte (entre ellas todo el panel admin). Las llamadas ya
        // comprueban el token antes de usarlo.
        $this->botToken = (string) config('services.discord.bot_token', '');
    }

    /**
     * Enviar notificación de match encontrado a todos los jugadores
     */
    public function notifyMatchFound(ArenaMatch $match): void
    {
        $this->despues(fn () => $this->enviarMatchFound($match));
    }

    private function enviarMatchFound(ArenaMatch $match): void
    {
        $allPlayers = $match->getAllPlayers();
        
        foreach ($allPlayers as $playerData) {
            $this->sendMatchNotification($playerData, $match);
        }
    }

    /**
     * Enviar notificación individual de match
     */
    private function sendMatchNotification(array $playerData, ArenaMatch $match): void
    {
        $discordId = (string) ($playerData['discord_id'] ?? '');
        if ($this->shouldSkipDirectMessage($discordId)) {
            return;
        }
        
        try {
            $message = $this->enIdioma($discordId, fn () => $this->buildMatchMessage($match, $playerData));

            // Crear DM channel
            $dmChannel = $this->createDMChannel($discordId);
            
            if (!$dmChannel) {
                return;
            }

            // Enviar mensaje
            $this->sendMessage($dmChannel['id'], $message);
            
        } catch (\Exception $e) {
            Log::error("Failed to send Discord notification to $discordId: " . $e->getMessage());
        }
    }

    /**
     * Construir mensaje de match encontrado
     */
    private function buildMatchMessage(ArenaMatch $match, array $playerData): array
    {
        $playerId = isset($playerData['player_id']) ? (int) $playerData['player_id'] : null;
        $discordId = isset($playerData['discord_id']) ? (string) $playerData['discord_id'] : null;
        // Si no se resuelve el lado se asume team_a, como siempre. Ojo con esa
        // suposicion mas abajo: en 2v2 equivocarse solo enseña el equipo que no
        // era, pero en duelo el campo se llama "Tu rival", asi que un lado mal
        // adivinado mandaria al jugador a buscarse a si mismo.
        $ladoResuelto = $match->getTeamSideForPlayer($playerId, $discordId);
        $teamSide = $ladoResuelto ?? 'team_a';
        $ownTeam = $match->getTeamBySide($teamSide);
        $rivalRealm = $match->getOpponentRealmForPlayer($playerId, $discordId);
        $rivalRealmName = ArenaMatch::REALMS[$rivalRealm] ?? strtoupper((string) $rivalRealm);
        $matchUrl = route('matches.show', $match);

        // En el duelo el nombre del rival es publico, y este aviso es justo
        // donde hace falta: es lo que el jugador lee antes de ir a la zona. Con
        // "Tu equipo" a solas no decia nada -el equipo es el mismo que lo lee-,
        // asi que el hueco lo ocupa a quien va a buscar.
        // Solo se nombra al rival cuando de verdad se sabe de que lado esta
        // quien lee. Sin el $ladoResuelto, adivinar mal convertia el aviso en
        // una mentira con nombre y apellidos.
        $esDuelo = $ladoResuelto !== null && ArenaMode::revealsRivalNames($match->arena_mode);
        $rivalTeam = $match->getTeamBySide($teamSide === 'team_a' ? 'team_b' : 'team_a');

        $embed = [
            'title' => $match->isFriendly() ? __('🤝 ¡Amistoso encontrado!') : __('🎯 ¡Match Encontrado!'),
            'description' => __('**Codigo:** `:codigo`', ['codigo' => $match->match_code]) . "\n" . __('**Zona:** :zona', ['zona' => \Illuminate\Support\Str::replaceFirst('Zona', __('Zona'), (string) $match->zone_name)]) . "\n" . __('**Reino rival:** :reino', ['reino' => __($rivalRealmName)]),
            'color' => 0xFF6B35, // Orange color
            'fields' => [
                $esDuelo
                    ? [
                        'name' => __('Tu rival'),
                        'value' => $this->formatTeamList($rivalTeam, true),
                        'inline' => true,
                    ]
                    : [
                        'name' => __('Tu equipo'),
                        'value' => $this->formatTeamList($ownTeam),
                        'inline' => true,
                    ],
                [
                    'name' => __('Modo'),
                    'value' => __($match->queue_mode_name),
                    'inline' => true
                ],
                $match->isFriendly()
                    ? [
                        'name' => __('Amistoso'),
                        'value' => __('No mueve el ranking ni hace falta reportar. Cuando acabéis, termínalo desde la web.'),
                        'inline' => false,
                    ]
                    : [
                        'name' => __('Reporte'),
                        'value' => __('Usa `/reportar :token` al terminar.', ['token' => $match->report_token]),
                        'inline' => false
                    ],
                [
                    'name' => __('Aceptar desde la web'),
                    'value' => $matchUrl,
                    'inline' => false
                ]
            ],
            'footer' => [
                'text' => __('Tienes 5 minutos para aceptar')
            ],
            'timestamp' => ($match->created_at ?? now())->toISOString()
        ];

        $components = [
            [
                'type' => 1, // Action Row
                'components' => [
                    [
                        'type' => 2, // Button
                        'style' => 3, // Success (Green)
                        'label' => __('Aceptar Match'),
                        'emoji' => ['name' => '✅'],
                        'url' => $matchUrl
                    ],
                    [
                        'type' => 2, // Button  
                        'style' => 4, // Danger (Red)
                        'label' => __('Rechazar'),
                        'emoji' => ['name' => '❌'],
                        'url' => $matchUrl
                    ]
                ]
            ]
        ];

        return [
            'embeds' => [$embed],
            'components' => [
                [
                    'type' => 1,
                    'components' => [
                        [
                            'type' => 2,
                            'style' => 5,
                            'label' => __('Abrir Match'),
                            'url' => $matchUrl,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Formatear lista de jugadores para embed
     */
    private function formatTeamList(array $team, bool $conSubclase = false): string
    {
        $lines = [];
        foreach ($team as $player) {
            $linea = "• {$player['character_name']}";

            if ($conSubclase) {
                $subclase = Player::SUBCLASSES[$player['subclass'] ?? ''] ?? null;

                if ($subclase !== null) {
                    $linea .= " ({$subclase})";
                }
            }

            $lines[] = $linea;
        }
        return implode("\n", $lines) ?: __('Sin jugadores');
    }

    /**
     * Crear canal DM con usuario
     */
    private function createDMChannel(string $userId): ?array
    {
        if ($this->shouldSkipDirectMessage($userId)) {
            return null;
        }

        // El canal de DM con un usuario no cambia: se guarda y cada aviso
        // cuesta una llamada a Discord en vez de dos.
        $cacheado = Cache::get(self::DM_CACHE_PREFIX . $userId);
        if (is_string($cacheado) && $cacheado !== '') {
            return ['id' => $cacheado];
        }

        $response = $this->http()->post($this->baseUrl . '/users/@me/channels', [
            'recipient_id' => $userId
        ]);

        if ($response->successful()) {
            $canal = $response->json();
            if (is_array($canal) && !empty($canal['id'])) {
                Cache::put(self::DM_CACHE_PREFIX . $userId, (string) $canal['id'], now()->addDays(7));
            }

            return $canal;
        }

        $this->logDiscordFailure('create DM channel', $response, ['discord_id' => $userId]);
        return null;
    }

    /**
     * Enviar mensaje a canal
     */
    private function sendMessage(string $channelId, array $message): bool
    {
        $response = $this->http()->post($this->baseUrl . "/channels/$channelId/messages", $message);

        if ($response->successful()) {
            return true;
        }

        $this->logDiscordFailure('send message', $response, ['channel_id' => $channelId]);
        return false;
    }

    /**
     * Publica un mensaje en un canal del servidor (no un DM).
     *
     * Sale despues de responder, como el resto de avisos.
     */
    public function publicarEnCanal(string $channelId, array $message): void
    {
        if ($channelId === '') {
            return;
        }

        $this->despues(fn () => $this->sendMessage($channelId, $message));
    }

    /**
     * Las llamadas a Discord, siempre con limite de tiempo. Sin el, un Discord
     * lento dejaba colgada la peticion el tiempo que tardase.
     */
    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bot ' . $this->botToken,
            'Content-Type' => 'application/json',
        ])->connectTimeout(3)->timeout(5);
    }

    /** El idioma de un usuario de Discord; español si no se sabe. */
    private function idiomaDe(string $discordId): string
    {
        try {
            $codigo = \App\Models\User::query()->where('discord_id', $discordId)->value('locale');
        } catch (\Throwable) {
            $codigo = null;
        }

        return \App\Support\I18n\Idioma::normalizar($codigo) ?? \App\Support\I18n\Idioma::FUENTE;
    }

    /** Construye un mensaje en el idioma de quien lo va a leer. */
    private function enIdioma(string $discordId, \Closure $construir): mixed
    {
        $anterior = app()->getLocale();
        app()->setLocale($this->idiomaDe($discordId));

        try {
            return $construir();
        } finally {
            app()->setLocale($anterior);
        }
    }

    /**
     * Los mensajes de Discord salen DESPUES de guardar y de responder.
     *
     * Antes se mandaban dentro de la peticion que creaba el cruce: dos llamadas
     * por jugador (abrir el DM y escribir), doce en un 3v3. Si Discord iba
     * lento, el jugador tardaba en saber que habia entrado en partida. Ahora es
     * como con el push: `afterCommit` espera a que el cruce este guardado y
     * `terminating` lo manda cuando el jugador ya tiene su respuesta.
     */
    private function despues(\Closure $trabajo): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $mandar = function () use ($trabajo) {
            try {
                $trabajo();
            } catch (\Throwable $e) {
                // Un aviso que no sale nunca puede tumbar lo que lo provoco.
                Log::warning('No se pudo mandar un aviso de Discord', ['error' => $e->getMessage()]);
            }
        };

        DB::afterCommit(function () use ($mandar) {
            // Desde el cron no hay nadie esperando: se manda ya.
            if (app()->runningInConsole() && !app()->runningUnitTests()) {
                $mandar();

                return;
            }

            app()->terminating($mandar);
        });
    }

    /**
     * Verificar si el bot está configurado
     */
    public function isConfigured(): bool
    {
        return !empty($this->botToken);
    }

    /**
     * Enviar notificación de match cancelado
     */
    public function notifyMatchCancelled(ArenaMatch $match, string $reason = 'timeout'): void
    {
        $this->despues(fn () => $this->enviarMatchCancelled($match, $reason));
    }

    private function enviarMatchCancelled(ArenaMatch $match, string $reason = 'timeout'): void
    {
        if (!$this->isConfigured()) return;

        $allPlayers = $match->getAllPlayers();

        $construir = fn () => [
            'embeds' => [
                [
                    'title' => __('❌ Match Cancelado'),
                    'description' => __('El match `:codigo` ha sido cancelado.', ['codigo' => $match->match_code]),
                    'color' => 0xFF0000, // Red
                    'fields' => [
                        [
                            'name' => __('Razón'),
                            'value' => $reason === 'timeout' ? __('Tiempo agotado para aceptar') : ucfirst(str_replace('_', ' ', $reason))
                        ]
                    ]
                ]
            ]
        ];

        foreach ($allPlayers as $playerData) {
            try {
                $dmChannel = $this->createDMChannel($playerData['discord_id']);
                if ($dmChannel) {
                    $this->sendMessage($dmChannel['id'], $this->enIdioma((string) $playerData['discord_id'], $construir));
                }
            } catch (\Exception $e) {
                Log::error("Failed to send cancellation notification: " . $e->getMessage());
            }
        }
    }

    /**
     * Enviar notificación de match aceptado por todos
     */
    public function notifyMatchAccepted(ArenaMatch $match): void
    {
        $this->despues(fn () => $this->enviarMatchAccepted($match));
    }

    private function enviarMatchAccepted(ArenaMatch $match): void
    {
        if (!$this->isConfigured()) return;

        $allPlayers = $match->getAllPlayers();
        
        $construir = fn () => [
            'embeds' => [
                [
                    'title' => __('✅ ¡Match Aceptado!'),
                    'description' => __('Todos los jugadores han aceptado el match `:codigo`', ['codigo' => $match->match_code]),
                    'color' => 0x00FF00, // Green
                    'fields' => [
                        [
                            'name' => __('Zona de combate'),
                            'value' => \Illuminate\Support\Str::replaceFirst('Zona', __('Zona'), (string) $match->zone_name)
                        ],
                        [
                            'name' => __('Estado'),
                            'value' => __('El match está listo para comenzar')
                        ]
                    ]
                ]
            ]
        ];

        foreach ($allPlayers as $playerData) {
            try {
                $dmChannel = $this->createDMChannel($playerData['discord_id']);
                if ($dmChannel) {
                    $this->sendMessage($dmChannel['id'], $this->enIdioma((string) $playerData['discord_id'], $construir));
                }
            } catch (\Exception $e) {
                Log::error("Failed to send acceptance notification: " . $e->getMessage());
            }
        }
    }

    public function notifyReportSubmitted(ArenaMatch $match, MatchReport $report): void
    {
        $this->despues(fn () => $this->enviarReportSubmitted($match, $report));
    }

    private function enviarReportSubmitted(ArenaMatch $match, MatchReport $report): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $winnerRealm = $report->claimed_winner_team === 'draw'
            ? null
            : ($report->claimed_winner_team === 'team_a' ? $match->team_a_realm : $match->team_b_realm);

        $message = fn () => [
            'embeds' => [
                [
                    'title' => __('Result report submitted'),
                    'description' => __('A result report was submitted for `:codigo`.', ['codigo' => $match->match_code]),
                    'color' => 0x3B82F6,
                    'fields' => [
                        [
                            'name' => __('Claimed winner'),
                            'value' => $winnerRealm ? __(ArenaMatch::REALMS[$winnerRealm] ?? strtoupper((string) $winnerRealm)) : __('⚔️ Empate'),
                            'inline' => true,
                        ],
                        [
                            'name' => __('Status'),
                            'value' => __('Pending rival confirmation'),
                            'inline' => true,
                        ],
                    ],
                ],
            ],
        ];

        $this->broadcastToMatchPlayers($match, $message);
    }

    public function notifyReportResolved(ArenaMatch $match, array $payload): void
    {
        $this->despues(fn () => $this->enviarReportResolved($match, $payload));
    }

    private function enviarReportResolved(ArenaMatch $match, array $payload): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $winnerRealm = ArenaMatch::REALMS[$payload['winner_realm'] ?? ''] ?? strtoupper((string) ($payload['winner_realm'] ?? ''));

        $message = fn () => [
            'embeds' => [
                [
                    'title' => __('Match resolved'),
                    'description' => __('The match `:codigo` was resolved.', ['codigo' => $match->match_code]),
                    'color' => 0x22C55E,
                    'fields' => [
                        [
                            'name' => __('Winner'),
                            'value' => __($winnerRealm),
                            'inline' => true,
                        ],
                        [
                            'name' => __('Status'),
                            'value' => __($match->status_name),
                            'inline' => true,
                        ],
                    ],
                ],
            ],
        ];

        $this->broadcastToMatchPlayers($match, $message);
    }

    public function notifyMatchDisputed(ArenaMatch $match, MatchReport $report): void
    {
        $this->despues(fn () => $this->enviarMatchDisputed($match, $report));
    }

    private function enviarMatchDisputed(ArenaMatch $match, MatchReport $report): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $message = fn () => [
            'embeds' => [
                [
                    'title' => __('Match disputed'),
                    'description' => __('The report for `:codigo` was disputed and now needs admin review.', ['codigo' => $match->match_code]),
                    'color' => 0xF59E0B,
                    'fields' => [
                        [
                            'name' => __('Report status'),
                            'value' => __($report->status_name),
                            'inline' => true,
                        ],
                    ],
                ],
            ],
        ];

        $this->broadcastToMatchPlayers($match, $message);
    }

    private function broadcastToMatchPlayers(ArenaMatch $match, \Closure $message): void
    {
        foreach ($match->getAllPlayers() as $playerData) {
            $discordId = (string) ($playerData['discord_id'] ?? '');
            if ($this->shouldSkipDirectMessage($discordId)) {
                continue;
            }

            try {
                $dmChannel = $this->createDMChannel($discordId);
                if ($dmChannel) {
                    $this->sendMessage($dmChannel['id'], $this->enIdioma($discordId, $message));
                }
            } catch (\Throwable $e) {
                Log::error("Failed to broadcast Discord message to {$discordId}: " . $e->getMessage());
            }
        }
    }

    private function shouldSkipDirectMessage(string $discordId): bool
    {
        if ($discordId === '') {
            Log::warning('Skipping Discord notification for player without discord_id');
            return true;
        }

        if (preg_match('/^\d{17,20}$/', $discordId) === 1) {
            return false;
        }

        Log::info('Skipping Discord notification for non-deliverable discord_id', [
            'discord_id' => $discordId,
        ]);

        return true;
    }

    private function logDiscordFailure(string $action, Response $response, array $context = []): void
    {
        $payload = $response->json();
        $discordCode = is_array($payload) ? ($payload['code'] ?? null) : null;
        $logContext = array_merge($context, [
            'status' => $response->status(),
            'discord_code' => $discordCode,
            'body' => $payload ?? $response->body(),
        ]);

        if (in_array($discordCode, [50035, 50278], true)) {
            Log::warning("Discord {$action} skipped", $logContext);
            return;
        }

        Log::error("Discord {$action} failed", $logContext);
    }
}
