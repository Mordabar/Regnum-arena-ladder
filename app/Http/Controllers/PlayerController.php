<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\LadderCacheService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlayerController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function store(Request $request)
    {
        if (auth()->user()->players()->count() >= 5) {
            return redirect()->route('lobby')->with('error', 'Maximo 5 personajes permitidos por cuenta');
        }

        $validated = $request->validate([
            'character_name' => [
                'required',
                'string',
                'min:3',
                'max:25',
                'regex:/^[a-zA-Z0-9_\s-]+$/',
                Rule::unique('players')->where(function ($query) use ($request) {
                    return $query->where('realm', $request->realm);
                }),
            ],
            'subclass' => ['required', Rule::in(array_keys(Player::SUBCLASSES))],
            'realm' => ['required', Rule::in(array_keys(Player::REALMS))],
        ], [
            'character_name.required' => 'El nombre del personaje es obligatorio',
            'character_name.min' => 'El nombre debe tener al menos 3 caracteres',
            'character_name.max' => 'El nombre no puede tener mas de 25 caracteres',
            'character_name.regex' => 'Solo se permiten letras, numeros, espacios, guiones y guiones bajos',
            'character_name.unique' => 'Este nombre ya existe en el reino seleccionado',
            'subclass.required' => 'Debes seleccionar una subclase',
            'subclass.in' => 'Subclase no valida',
            'realm.required' => 'Debes seleccionar un reino',
            'realm.in' => 'Reino no valido',
        ]);

        Player::create([
            'user_id' => auth()->id(),
            'character_name' => $validated['character_name'],
            'subclass' => $validated['subclass'],
            'realm' => $validated['realm'],
        ]);

        app(LadderCacheService::class)->forgetSummary();

        return redirect()->route('lobby')->with('success', 'Personaje registrado exitosamente');
    }

    public function update(Request $request, Player $player)
    {
        if ($player->user_id !== auth()->id()) {
            return redirect()->route('lobby')->with('error', 'No tienes permisos para editar este personaje');
        }

        $validated = $request->validate([
            'character_name' => [
                'required',
                'string',
                'min:3',
                'max:25',
                'regex:/^[a-zA-Z0-9_\s-]+$/',
                Rule::unique('players')->where(function ($query) use ($player) {
                    return $query->where('realm', $player->realm);
                })->ignore($player->id),
            ],
        ], [
            'character_name.required' => 'El nombre del personaje es obligatorio',
            'character_name.min' => 'El nombre debe tener al menos 3 caracteres',
            'character_name.max' => 'El nombre no puede tener mas de 25 caracteres',
            'character_name.regex' => 'Solo se permiten letras, numeros, espacios, guiones y guiones bajos',
            'character_name.unique' => 'Este nombre ya existe en el reino',
        ]);

        $player->update([
            'character_name' => $validated['character_name'],
        ]);

        app(LadderCacheService::class)->forgetSummary();

        return redirect()->route('lobby')->with('success', 'Personaje actualizado exitosamente');
    }

    public function destroy(Player $player)
    {
        if ($player->user_id !== auth()->id()) {
            return redirect()->route('lobby')->with('error', 'No tienes permisos para eliminar este personaje');
        }

        if (auth()->user()->players()->count() <= 1) {
            return redirect()->route('lobby')->with('error', 'No puedes eliminar tu ultimo personaje');
        }

        $characterName = $player->character_name;

        if ($player->matches_played > 0) {
            $player->update([
                'is_active' => false,
                'character_name' => $player->character_name . ' [INACTIVO]',
            ]);

            app(LadderCacheService::class)->forgetSummary();

            return redirect()->route('lobby')->with('warning', "Personaje '{$characterName}' desactivado (tenia {$player->matches_played} partidas). Sus estadisticas se mantienen en el ranking pero no podra usarse mas.");
        }

        $player->delete();
        app(LadderCacheService::class)->forgetSummary();

        return redirect()->route('lobby')->with('success', "Personaje '{$characterName}' eliminado completamente (sin partidas jugadas)");
    }

    public function reactivate(Player $player)
    {
        if ($player->user_id !== auth()->id()) {
            return redirect()->route('lobby')->with('error', 'No tienes permisos para reactivar este personaje');
        }

        if ($player->is_active) {
            return redirect()->route('lobby')->with('error', 'Este personaje ya esta activo');
        }

        $cleanName = str_replace(' [INACTIVO]', '', $player->character_name);

        $nameExists = Player::where('character_name', $cleanName)
            ->where('realm', $player->realm)
            ->where('id', '!=', $player->id)
            ->where('is_active', true)
            ->exists();

        if ($nameExists) {
            return redirect()->route('lobby')->with('error', "No se puede reactivar: el nombre '{$cleanName}' ya esta en uso por otro personaje activo");
        }

        $player->update([
            'is_active' => true,
            'character_name' => $cleanName,
        ]);

        app(LadderCacheService::class)->forgetSummary();

        return redirect()->route('lobby')->with('success', "Personaje '{$cleanName}' reactivado exitosamente");
    }
}
