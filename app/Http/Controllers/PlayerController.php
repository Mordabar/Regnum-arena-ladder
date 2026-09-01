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

    /**
     * Asistente de creacion.
     *
     * Vive en su propia pagina y no en un formulario lateral del lobby porque
     * ahora hay una vista previa 3D que necesita sitio, y porque elegir reino y
     * subclase son decisiones irreversibles: merecen una pantalla propia en vez
     * de un desplegable al lado de otra cosa.
     */
    public function create()
    {
        if (auth()->user()->players()->visibleToOwner()->count() >= 5) {
            return redirect()->route('lobby')->with('error', 'Maximo 5 personajes permitidos por cuenta');
        }

        return view('players.create');
    }

    public function store(Request $request)
    {
        // Los eliminados no ocupan slot: la idea de borrar es justamente poder
        // volver a crear ese personaje.
        if (auth()->user()->players()->visibleToOwner()->count() >= 5) {
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
            // La raza tiene que existir DENTRO del reino elegido: un enano no
            // es de Ignis. Se valida en el servidor y no solo escondiendo
            // opciones en el formulario.
            'race' => ['required', 'string', function ($attribute, $value, $fail) use ($request) {
                if (!Player::raceBelongsToRealm($value, $request->input('realm'))) {
                    $fail('Esa raza no pertenece al reino elegido.');
                }
            }],
            'gender' => ['required', Rule::in(array_keys(Player::GENDERS))],
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
            'race.required' => 'Debes elegir una raza',
            'gender.required' => 'Debes elegir el sexo del personaje',
        ]);

        Player::create([
            'user_id' => auth()->id(),
            'character_name' => $validated['character_name'],
            'subclass' => $validated['subclass'],
            'realm' => $validated['realm'],
            'race' => $validated['race'],
            'gender' => $validated['gender'],
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

        if (auth()->user()->players()->visibleToOwner()->count() <= 1) {
            return redirect()->route('lobby')->with('error', 'No puedes eliminar tu ultimo personaje');
        }

        $characterName = $player->character_name;

        if ($player->matches_played > 0) {
            // No se borra la fila: sus partidas ya jugadas siguen contando en el
            // historial y en los enfrentamientos donde aparece.
            $player->update([
                'is_active' => false,
                'deactivated_reason' => Player::DEACTIVATED_BY_PLAYER,
                'deactivated_at' => now(),
                'character_name' => $player->character_name . Player::DELETED_NAME_SUFFIX,
            ]);

            app(LadderCacheService::class)->forgetSummary();

            return redirect()->route('lobby')->with('warning', "Personaje '{$characterName}' eliminado. Su historial de enfrentamientos se conserva para no falsear las partidas ya jugadas, pero sale del ranking y de tu lobby, y el nombre queda libre para volver a crearlo. Si necesitas recuperarlo tal cual, pideselo a un administrador.");
        }

        $player->delete();
        app(LadderCacheService::class)->forgetSummary();

        return redirect()->route('lobby')->with('success', "Personaje '{$characterName}' eliminado completamente (sin partidas jugadas)");
    }

}
