<?php

namespace App\Http\Controllers;

use App\Models\ArenaSeason;
use App\Models\SeasonPlayerStat;
use App\Services\SeasonClosingService;
use App\Services\SeasonPrizeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * El calendario de temporadas en el panel: fechas, cierre automatico, cerrar a
 * mano y abrir la siguiente, con las opciones de que hacer con el ranking.
 */
class AdminSeasonController extends Controller
{
    public function index(SeasonPrizeService $premios)
    {
        $disponible = Schema::hasTable('arena_seasons') && Schema::hasColumn('arena_seasons', 'auto_close');

        $actual = $disponible ? ArenaSeason::current() : null;

        $historial = $disponible
            ? ArenaSeason::query()
                ->where('status', ArenaSeason::STATUS_ARCHIVED)
                ->orderByDesc('ends_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();

        $congelados = $historial->isEmpty()
            ? []
            : SeasonPlayerStat::query()
                ->whereIn('season_id', $historial->pluck('id'))
                ->selectRaw('season_id, count(*) as total')
                ->groupBy('season_id')
                ->pluck('total', 'season_id')
                ->all();

        return view('admin.seasons', [
            'disponible' => $disponible,
            'actual' => $actual,
            'progreso' => $actual?->progreso(),
            'programada' => $disponible && Schema::hasColumn('arena_seasons', 'prizes_on_open') ? ArenaSeason::programada() : null,
            'historial' => $historial,
            'congelados' => $congelados,
            'zona' => ArenaSeason::zone(),
            'premios' => $premios,
        ]);
    }

    public function update(Request $request, ArenaSeason $season)
    {
        if ($season->status !== ArenaSeason::STATUS_ACTIVE) {
            return back()->withErrors(['error' => 'Solo se puede editar la temporada abierta: las cerradas son historia.']);
        }

        $datos = $request->validate([
            'name' => 'required|string|max:120',
            'starts_at' => 'required|date_format:Y-m-d\TH:i',
            'ends_at' => 'nullable|date_format:Y-m-d\TH:i',
            'auto_close' => 'nullable|boolean',
            'next_name' => 'nullable|string|max:120',
            'next_duration_days' => 'nullable|integer|min:1|max:3650',
            'reset_on_close' => 'nullable|boolean',
            'next_prizes_enabled' => 'nullable|boolean',
            'open_next' => 'nullable|boolean',
        ], [
            'starts_at.required' => 'Pon la fecha de inicio.',
            'starts_at.date_format' => 'La fecha de inicio no es valida.',
            'ends_at.date_format' => 'La fecha de fin no es valida.',
        ]);

        $inicio = $this->enZona($datos['starts_at']);
        $fin = filled($datos['ends_at'] ?? null) ? $this->enZona($datos['ends_at']) : null;
        $auto = $request->boolean('auto_close');

        if ($fin !== null && !$fin->gt($inicio)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin tiene que ser posterior a la de inicio.']);
        }

        if ($auto && $fin === null) {
            throw ValidationException::withMessages(['auto_close' => 'Para que se cierre sola hace falta una fecha de fin.']);
        }

        // Con cierre automatico, una fecha ya pasada la cerraria en el proximo
        // minuto sin que nadie lo hubiera decidido. Si es lo que se quiere,
        // para eso esta el boton de cerrar.
        if ($auto && $fin->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => 'Esa fecha ya paso: con el cierre automatico la temporada se cerraria en el proximo minuto. Pon una fecha futura, o usa "Cerrar la temporada" si es lo que quieres.']);
        }

        $season->update([
            'name' => trim($datos['name']),
            'starts_at' => $inicio,
            'ends_at' => $fin,
            'auto_close' => $auto,
            'next_name' => filled($datos['next_name'] ?? null) ? trim($datos['next_name']) : null,
            'next_duration_days' => filled($datos['next_duration_days'] ?? null) ? (int) $datos['next_duration_days'] : null,
            'reset_on_close' => $request->boolean('reset_on_close'),
            'next_prizes_enabled' => $request->boolean('next_prizes_enabled'),
            'open_next' => $request->boolean('open_next'),
        ]);

        return back()->with('success', 'Calendario guardado.' . ($auto ? ' Se cerrará sola el ' . ArenaSeason::fechaCorta($fin) . ' a las ' . $fin->copy()->setTimezone(ArenaSeason::zone())->format('H:i') . '.' : ''));
    }

    /**
     * Cierra la temporada en curso y deja su podio en el Salon de la Fama.
     *
     * Pide el nombre escrito a mano, igual que reiniciar el ranking: cerrar una
     * temporada no se deshace, y un clic de mas no puede archivarla.
     */
    public function close(Request $request, SeasonClosingService $cierre)
    {
        $validated = $request->validate([
            'confirmacion' => 'required|in:CERRAR',
            'siguiente' => 'nullable|string|max:120',
            'forzar' => 'nullable|boolean',
            'esperada' => 'nullable|integer',
            'duracion_dias' => 'nullable|integer|min:1|max:3650',
            'resetear' => 'nullable|boolean',
            'premios_siguiente' => 'nullable|boolean',
            'abrir_siguiente' => 'nullable|boolean',
        ], [
            'confirmacion.required' => 'Escribe CERRAR para confirmar.',
            'confirmacion.in' => 'Escribe CERRAR para confirmar.',
        ]);

        // Solo se pasa lo que el formulario trajo: lo que falte se toma de la
        // propia temporada, que es lo que ella tiene configurado.
        $opciones = ['motivo' => 'manual'];

        if ($request->filled('esperada')) {
            $opciones['esperada'] = (int) $validated['esperada'];
        }

        if ($request->has('duracion_dias')) {
            $opciones['duracion_dias'] = filled($validated['duracion_dias'] ?? null) ? (int) $validated['duracion_dias'] : null;
        }

        if ($request->has('resetear')) {
            $opciones['resetear'] = $request->boolean('resetear');
        }

        if ($request->has('abrir_siguiente')) {
            $opciones['abrir_siguiente'] = $request->boolean('abrir_siguiente');
        }

        if ($request->has('premios_siguiente')) {
            $opciones['premios_siguiente'] = $request->boolean('premios_siguiente');
        }

        $resultado = $cierre->cerrar($validated['siguiente'] ?? null, $request->boolean('forzar'), $opciones);

        if (!$resultado['ok']) {
            return back()->withErrors(['error' => $resultado['motivo']]);
        }

        $mensaje = sprintf(
            '%s cerrada con %d personaje(s) en la vitrina. %s',
            $resultado['season']->name,
            $resultado['congelados'],
            $resultado['siguiente']
                ? 'Ya esta abierta ' . $resultado['siguiente']->name . '.'
                : 'El ladder queda en pausa: solo se juegan amistosos hasta que abras otra temporada.'
        );

        if (!empty($resultado['reinicio'])) {
            $mensaje .= sprintf(' Ranking reiniciado: %d enfrentamiento(s) borrados.', $resultado['reinicio']['matches_deleted']);
        }

        return back()->with('success', $mensaje);
    }

    /** Abre una temporada cuando no hay ninguna abierta. */
    public function open(Request $request, SeasonClosingService $cierre)
    {
        $datos = $request->validate([
            'name' => 'required|string|max:120',
            'starts_at' => 'nullable|date_format:Y-m-d\TH:i',
            'ends_at' => 'nullable|date_format:Y-m-d\TH:i',
            'auto_close' => 'nullable|boolean',
        ]);

        $inicio = filled($datos['starts_at'] ?? null) ? $this->enZona($datos['starts_at']) : now();
        $fin = filled($datos['ends_at'] ?? null) ? $this->enZona($datos['ends_at']) : null;

        if ($fin !== null && !$fin->gt($inicio)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin tiene que ser posterior a la de inicio.']);
        }

        $resultado = $cierre->abrirNueva($datos['name'], $inicio, $fin, $request->boolean('auto_close'));

        if (!$resultado['ok']) {
            return back()->withErrors(['error' => $resultado['motivo']]);
        }

        return back()->with('success', $resultado['season']->name . ' abierta.');
    }

    /** Programa una temporada para que se abra sola en una fecha futura. */
    public function schedule(Request $request, SeasonClosingService $cierre)
    {
        $datos = $request->validate([
            'name' => 'required|string|max:120',
            'starts_at' => 'required|date_format:Y-m-d\TH:i',
            'duration_days' => 'nullable|integer|min:1|max:3650',
            'prizes' => 'nullable|boolean',
            'reset_on_close' => 'nullable|boolean',
            'open_next' => 'nullable|boolean',
        ], [
            'starts_at.required' => 'Pon la fecha y la hora de inicio.',
            'starts_at.date_format' => 'La fecha de inicio no es valida.',
        ]);

        $inicio = $this->enZona($datos['starts_at']);

        $resultado = $cierre->programar($datos['name'], $inicio, [
            'dias' => filled($datos['duration_days'] ?? null) ? (int) $datos['duration_days'] : null,
            'premios' => $request->boolean('prizes'),
            'resetear' => $request->boolean('reset_on_close'),
            'abrir_siguiente' => $request->boolean('open_next'),
        ]);

        if (!$resultado['ok']) {
            return back()->withErrors(['error' => $resultado['motivo']])->withInput();
        }

        return back()->with('success', sprintf(
            '%s programada: se abrirá sola el %s a las %s.',
            $resultado['season']->name,
            ArenaSeason::fechaCorta($inicio),
            $inicio->copy()->setTimezone(ArenaSeason::zone())->format('H:i')
        ));
    }

    /** Cancela la temporada programada. */
    public function cancelSchedule(ArenaSeason $season)
    {
        if ($season->status !== ArenaSeason::STATUS_SCHEDULED) {
            return back()->withErrors(['error' => 'Esa temporada no esta programada.']);
        }

        $season->delete();

        return back()->with('success', 'Temporada programada cancelada.');
    }

    /** "2026-11-29T23:59" escrito en la zona de las temporadas, a UTC. */
    private function enZona(string $valor): Carbon
    {
        return Carbon::createFromFormat('Y-m-d\TH:i', $valor, ArenaSeason::zone())->utc();
    }
}
