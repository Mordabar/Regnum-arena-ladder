<?php

namespace App\Support;

use App\Models\ArenaMatch;
use App\Models\MatchReport;
use Illuminate\Support\Facades\Route;

/**
 * Estructura del menu del panel admin.
 *
 * Vive en una clase y no dentro de la vista porque el menu es el mismo en las
 * ocho pantallas: si cada una lo repite, tarde o temprano una se queda sin una
 * entrada (era el caso de "Testing aislado", que no estaba en ningun menu y
 * solo se llegaba escribiendo la URL a mano).
 */
class AdminNavigation
{
    /**
     * Secciones del menu, con el contador de pendientes cuando lo hay.
     *
     * Recibe los contadores ya calculados en vez de pedirlos: quien pinta el
     * menu tambien los necesita para la cabecera, y calcularlos dos veces son
     * dos consultas de mas en cada carga de cada pantalla.
     *
     * @param  array{inbox: int, confirmations: int, disputes: int}  $counts
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    public static function sections(array $counts): array
    {

        return [
            [
                'label' => 'Operacion',
                'items' => [
                    self::item('admin.dashboard', 'Resumen', 'gauge'),
                    self::item('admin.inbox', 'Moderacion', 'inbox', $counts['inbox']),
                    self::item('admin.matches.index', 'Enfrentamientos', 'swords'),
                    self::item('admin.players.index', 'Jugadores', 'users'),
                ],
            ],
            [
                'label' => 'Configuracion',
                'items' => [
                    self::item('admin.settings', 'Reglas del ladder', 'sliders'),
                    self::item('admin.zones', 'Zonas de combate', 'map'),
                ],
            ],
            [
                'label' => 'Herramientas',
                'items' => [
                    self::item('admin.testing', 'Entorno de pruebas', 'flask'),
                ],
            ],
        ];
    }

    /**
     * Lo que espera una decision humana ahora mismo.
     *
     * @return array{inbox: int, confirmations: int, disputes: int}
     */
    public static function pendingCounts(): array
    {
        $confirmations = MatchReport::query()->where('status', 'pending_confirmation')->count();
        $disputes = ArenaMatch::query()->where('status', 'disputed')->count();

        return [
            'confirmations' => $confirmations,
            'disputes' => $disputes,
            'inbox' => $confirmations + $disputes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $route, string $label, string $icon, int $count = 0): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'url' => Route::has($route) ? route($route) : '#',
            'count' => $count,
            'active' => request()->routeIs($route) || self::isChildRoute($route),
        ];
    }

    /**
     * El detalle de un match (admin.matches.show) tiene que iluminar
     * "Enfrentamientos": si no, al abrir uno el menu se queda sin seccion
     * activa y se pierde el sentido de ubicacion.
     */
    private static function isChildRoute(string $route): bool
    {
        return $route === 'admin.matches.index' && request()->routeIs('admin.matches.show');
    }
}
