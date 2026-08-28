<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use App\Models\Player;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordBotService
{
    private string $botToken;
    private string $baseUrl = 'https://discord.com/api/v10';

    public function __construct()
    {
        $this->botToken = config('services.discord.bot_token');
    }

    /**
     * Enviar notificación de match encontrado a todos los jugadores
     */
    public function notifyMatchFound(ArenaMatch $match): void
    {
        if (!$this->isConfigured()) {
            Log::warning('Discord bot not configured, skipping notifications');
            return;
        }

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
            $message = $this->buildMatchMessage($match, $playerData);

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
        $teamSide = $match->getTeamSideForPlayer($playerId, $discordId) ?? 'team_a';
        $ownTeam = $match->getTeamBySide($teamSide);
        $rivalRealm = $match->getOpponentRealmForPlayer($playerId, $discordId);
        $rivalRealmName = ArenaMatch::REALMS[$rivalRealm] ?? strtoupper((string) $rivalRealm);
        $matchUrl = route('matches.show', $match);

        $embed = [
            'title' => '🎯 ¡Match Encontrado!',
            'description' => "**Codigo:** `{$match->match_code}`\n**Zona:** {$match->zone_name}\n**Reino rival:** {$rivalRealmName}",
            'color' => 0xFF6B35, // Orange color
            'fields' => [
                [
                    'name' => 'Tu equipo',
                    'value' => $this->formatTeamList($ownTeam),
                    'inline' => true
                ],
                [
                    'name' => 'Modo',
                    'value' => $match->queue_mode_name,
                    'inline' => true
                ],
                [
                    'name' => 'Reporte',
                    'value' => "Usa `/reportar {$match->report_token}` al terminar.",
                    'inline' => false
                ],
                [
                    'name' => 'Aceptar desde la web',
                    'value' => $matchUrl,
                    'inline' => false
                ]
            ],
            'footer' => [
                'text' => 'Tienes 5 minutos para aceptar'
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
                        'label' => 'Aceptar Match',
                        'emoji' => ['name' => '✅'],
                        'url' => $matchUrl
                    ],
                    [
                        'type' => 2, // Button  
                        'style' => 4, // Danger (Red)
                        'label' => 'Rechazar',
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
                            'label' => 'Abrir Match',
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
    private function formatTeamList(array $team): string
    {
        $lines = [];
        foreach ($team as $player) {
            $lines[] = "• {$player['character_name']}";
        }
        return implode("\n", $lines) ?: "Sin jugadores";
    }

    /**
     * Crear canal DM con usuario
     */
    private function createDMChannel(string $userId): ?array
    {
        if ($this->shouldSkipDirectMessage($userId)) {
            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bot ' . $this->botToken,
            'Content-Type' => 'application/json'
        ])->post($this->baseUrl . '/users/@me/channels', [
            'recipient_id' => $userId
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $this->logDiscordFailure('create DM channel', $response, ['discord_id' => $userId]);
        return null;
    }

    /**
     * Enviar mensaje a canal
     */
    private function sendMessage(string $channelId, array $message): bool
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bot ' . $this->botToken,
            'Content-Type' => 'application/json'
        ])->post($this->baseUrl . "/channels/$channelId/messages", $message);

        if ($response->successful()) {
            return true;
        }

        $this->logDiscordFailure('send message', $response, ['channel_id' => $channelId]);
        return false;
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
        if (!$this->isConfigured()) return;

        $allPlayers = $match->getAllPlayers();
        
        $message = [
            'embeds' => [
                [
                    'title' => '❌ Match Cancelado',
                    'description' => "El match `{$match->match_code}` ha sido cancelado.",
                    'color' => 0xFF0000, // Red
                    'fields' => [
                        [
                            'name' => 'Razón',
                            'value' => $reason === 'timeout' ? 'Tiempo agotado para aceptar' : ucfirst(str_replace('_', ' ', $reason))
                        ]
                    ]
                ]
            ]
        ];
        
        foreach ($allPlayers as $playerData) {
            try {
                $dmChannel = $this->createDMChannel($playerData['discord_id']);
                if ($dmChannel) {
                    $this->sendMessage($dmChannel['id'], $message);
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
        if (!$this->isConfigured()) return;

        $allPlayers = $match->getAllPlayers();
        
        $message = [
            'embeds' => [
                [
                    'title' => '✅ ¡Match Aceptado!',
                    'description' => "Todos los jugadores han aceptado el match `{$match->match_code}`",
                    'color' => 0x00FF00, // Green
                    'fields' => [
                        [
                            'name' => 'Zona de combate',
                            'value' => $match->zone_name
                        ],
                        [
                            'name' => 'Estado',
                            'value' => 'El match está listo para comenzar'
                        ]
                    ]
                ]
            ]
        ];
        
        foreach ($allPlayers as $playerData) {
            try {
                $dmChannel = $this->createDMChannel($playerData['discord_id']);
                if ($dmChannel) {
                    $this->sendMessage($dmChannel['id'], $message);
                }
            } catch (\Exception $e) {
                Log::error("Failed to send acceptance notification: " . $e->getMessage());
            }
        }
    }

    public function notifyReportSubmitted(ArenaMatch $match, MatchReport $report): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $winnerRealm = $report->claimed_winner_team === 'draw'
            ? null
            : ($report->claimed_winner_team === 'team_a' ? $match->team_a_realm : $match->team_b_realm);

        $message = [
            'embeds' => [
                [
                    'title' => 'Result report submitted',
                    'description' => "A result report was submitted for `{$match->match_code}`.",
                    'color' => 0x3B82F6,
                    'fields' => [
                        [
                            'name' => 'Claimed winner',
                            'value' => $winnerRealm ? (ArenaMatch::REALMS[$winnerRealm] ?? strtoupper((string) $winnerRealm)) : '⚔️ Empate',
                            'inline' => true,
                        ],
                        [
                            'name' => 'Status',
                            'value' => 'Pending rival confirmation',
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
        if (!$this->isConfigured()) {
            return;
        }

        $winnerRealm = ArenaMatch::REALMS[$payload['winner_realm'] ?? ''] ?? strtoupper((string) ($payload['winner_realm'] ?? ''));

        $message = [
            'embeds' => [
                [
                    'title' => 'Match resolved',
                    'description' => "The match `{$match->match_code}` was resolved.",
                    'color' => 0x22C55E,
                    'fields' => [
                        [
                            'name' => 'Winner',
                            'value' => $winnerRealm,
                            'inline' => true,
                        ],
                        [
                            'name' => 'Status',
                            'value' => $match->status_name,
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
        if (!$this->isConfigured()) {
            return;
        }

        $message = [
            'embeds' => [
                [
                    'title' => 'Match disputed',
                    'description' => "The report for `{$match->match_code}` was disputed and now needs admin review.",
                    'color' => 0xF59E0B,
                    'fields' => [
                        [
                            'name' => 'Report status',
                            'value' => $report->status_name,
                            'inline' => true,
                        ],
                    ],
                ],
            ],
        ];

        $this->broadcastToMatchPlayers($match, $message);
    }

    private function broadcastToMatchPlayers(ArenaMatch $match, array $message): void
    {
        foreach ($match->getAllPlayers() as $playerData) {
            $discordId = (string) ($playerData['discord_id'] ?? '');
            if ($this->shouldSkipDirectMessage($discordId)) {
                continue;
            }

            try {
                $dmChannel = $this->createDMChannel($discordId);
                if ($dmChannel) {
                    $this->sendMessage($dmChannel['id'], $message);
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
