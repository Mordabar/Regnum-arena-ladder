@php
    $user = auth()->user();
    $visible = $user->players()->visibleToOwner()->count();

    // Cada subclase se explica por lo que hace en la arena, no por su nombre.
    // Un jugador nuevo no sabe que es un "marksman"; si sabe si quiere pegar de
    // cerca, de lejos o curar. Los textos viven en el modelo para que el panel
    // y el lobby no puedan decir cosas distintas.
    $subclasses = \App\Models\Player::SUBCLASSES;
    $subclassNotes = \App\Models\Player::SUBCLASS_NOTES;

    $realms = [
        'ignis'  => ['Ignis',  'Reino del fuego y el desierto.'],
        'alsius' => ['Alsius', 'Reino del hielo y la montaña.'],
        'syrtis' => ['Syrtis', 'Reino del bosque y la naturaleza.'],
    ];

    $oldRealm = old('realm', 'ignis');
    $oldSubclass = old('subclass', 'knight');
    $oldRace = old('race', \App\Models\Player::defaultRace($oldRealm));
    $oldGender = old('gender', 'male');

    // Todas las razas de los tres reinos van al HTML: el paso de raza se filtra
    // en el navegador segun el reino elegido, sin recargar la pagina.
    $races = \App\Models\Player::RACES;
    $raceNotes = \App\Models\Player::RACE_NOTES;
@endphp

@extends('layouts.arena')

@section('title', 'Crear guerrero - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <x-arena-breadcrumbs :items="[['label' => 'Lobby', 'url' => route('lobby')], ['label' => 'Crear guerrero']]" class="mb-6" />

    <section class="arena-panel-strong mb-6 p-6 arena-animate-in">
        <p class="arena-kicker">Reclutamiento</p>
        <h1 class="mt-2 text-3xl font-bold text-[color:var(--arena-gold-soft)]">Crear guerrero</h1>
        <p class="mt-2 max-w-2xl text-[color:var(--arena-sand)] arena-body-text">
            Cuatro pasos y ya estás en la arena. Vas viendo a tu guerrero mientras eliges:
            el reino le pone la capa, la raza el cuerpo, el sexo la figura y la subclase
            el arma y la armadura.
        </p>
        <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
            Slot {{ $visible + 1 }} de 5 · Solo el nombre se puede cambiar después: reino,
            raza, sexo y subclase quedan fijos.
        </p>
    </section>

    @if($errors->any())
        <div class="arena-panel mb-6 border-l-4 border-l-red-500/60 p-5">
            <p class="font-semibold text-red-200">Revisa estos puntos antes de continuar</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-100/90 arena-body-text">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

@php
    // Cada opcion con su marca. Doce razas en texto plano se leen como una
    // lista de la compra; el icono dice de un vistazo por donde va cada una.
    $raceIcons = [
        'nordo' => 'human', 'esquelio' => 'human', 'alturian' => 'human',
        'utghar' => 'horns', 'dwarf' => 'beard', 'molok' => 'hulk',
        'dark_elf' => 'ears', 'wood_elf' => 'ears', 'half_elf' => 'ears-short',
        'lamai' => 'ears-big',
    ];
@endphp

    <form method="POST" action="{{ route('player.register') }}" id="createChampionForm">
        @csrf
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_400px] items-start">

            {{-- ── VISTA PREVIA ── --}}
            {{-- Pegada arriba tambien en movil: si no, al llegar al paso de la
                 subclase el guerrero quedaba mil pixeles mas arriba y la
                 promesa de "vas viendo a tu guerrero mientras eliges" no se
                 cumplia justo en el formato donde mas gente lo va a usar. --}}
            <div class="arena-preview-dock order-1 z-10">
                <x-arena-champion
                    id="create-preview"
                    :realm="$oldRealm"
                    :subclass="$oldSubclass"
                    :race="$oldRace"
                    :gender="$oldGender"
                    height="var(--preview-height, clamp(320px, 44vh, 500px))"
                    class="arena-animate-in arena-stagger-1">
                    <div class="arena-champion-overlay">
                        <div class="absolute inset-x-5 bottom-5">
                            <h2 class="arena-champion-name" data-preview-name>Tu guerrero</h2>
                            <p class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                <span class="arena-champion-realm" data-preview-realm>Ignis</span>
                                <span data-preview-race>Esquelio</span>
                                <span data-preview-subclass>Caballero</span>
                            </p>
                        </div>
                    </div>
                </x-arena-champion>

                <p class="arena-champion-caption mt-3 text-center text-xs text-[color:var(--arena-muted)] arena-body-text">
                    Vista previa. Los modelos definitivos irán sustituyendo a estas siluetas por reino, raza y sexo.
                </p>
            </div>

            {{-- ── PASOS ── --}}
            <div class="flex flex-col gap-4 order-2">

                {{-- 1. Reino --}}
                <fieldset class="arena-wizard-step arena-animate-in arena-stagger-2" data-done="1">
                    <legend class="flex items-center gap-3 px-1">
                        <span class="arena-wizard-num">1</span>
                        <span class="text-base font-semibold text-white">Elige tu reino</span>
                    </legend>
                    <p class="mb-3 mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        Pelearás junto a los tuyos y contra los otros dos. No se puede cambiar después.
                    </p>
                    <div class="grid grid-cols-3 gap-2.5">
                        @foreach($realms as $key => $realm)
                            <label class="arena-choice"
                                   style="--choice-color: var(--arena-{{ $key === 'ignis' ? 'fire' : ($key === 'alsius' ? 'ice' : 'forest') }})">
                                <input type="radio" name="realm" value="{{ $key }}" required
                                       data-preview-input="realm"
                                       data-label="{{ $realm[0] }}"
                                       @checked($oldRealm === $key)>
                                <span class="arena-choice-body">
                                    <x-arena-realm-icon :realm="$key" size="md" />
                                    <span class="arena-choice-title">{{ $realm[0] }}</span>
                                    <span class="arena-choice-note">{{ $realm[1] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- 2. Raza y sexo --}}
                <fieldset class="arena-wizard-step arena-animate-in arena-stagger-3" data-done="1">
                    <legend class="flex items-center gap-3 px-1">
                        <span class="arena-wizard-num">2</span>
                        <span class="text-base font-semibold text-white">Elige tu raza</span>
                    </legend>
                    <p class="mb-3 mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        No da ninguna ventaja en el ladder, solo cambia cómo se ve tu guerrero.
                        Aun así es para siempre: tampoco se puede cambiar después.
                    </p>

                    <div class="grid gap-2.5 sm:grid-cols-2" data-race-options>
                        {{-- Sin JavaScript se ven las doce; el aviso explica por que. --}}
                        <p class="arena-choice-hint sm:col-span-2">
                            Cada raza pertenece a un reino. Aquí solo cuentan las del reino que hayas
                            elegido en el paso 1.
                        </p>
                        @foreach($races as $realmKey => $realmRaces)
                            @foreach($realmRaces as $raceKey => $raceLabel)
                                {{-- Todas las tarjetas salen visibles del servidor y es el
                                     JavaScript el que esconde las que no son del reino elegido.
                                     Al reves (ocultarlas aqui) dejaba sin ninguna raza que elegir
                                     a quien navega sin scripts y cambia de reino. --}}
                                <label class="arena-choice" data-race-of="{{ $realmKey }}">
                                    <input type="radio" name="race" value="{{ $raceKey }}" required
                                           data-preview-input="race"
                                           data-label="{{ $raceLabel }}"
                                           data-realm="{{ $realmKey }}"
                                           @checked($oldRace === $raceKey && $oldRealm === $realmKey)>
                                    <span class="arena-choice-body arena-choice-body-row">
                                        <span class="arena-choice-mark">
                                            <x-arena-icon :name="$raceIcons[$raceKey] ?? 'human'" class="h-5 w-5" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="arena-choice-title">{{ $raceLabel }}</span>
                                            <span class="arena-choice-note">{{ $raceNotes[$raceKey] ?? '' }}</span>
                                            <span class="arena-choice-realm">Solo {{ \App\Models\Player::REALMS[$realmKey] }}</span>
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        @endforeach
                    </div>

                    <p class="mb-2 mt-4 text-sm font-semibold text-white arena-body-text">Sexo</p>
                    <div class="grid grid-cols-2 gap-2.5">
                        @foreach(\App\Models\Player::GENDERS as $genderKey => $genderLabel)
                            <label class="arena-choice">
                                <input type="radio" name="gender" value="{{ $genderKey }}" required
                                       data-preview-input="gender"
                                       data-label="{{ $genderLabel }}"
                                       @checked($oldGender === $genderKey)>
                                <span class="arena-choice-body">
                                    <span class="arena-choice-mark">
                                        <x-arena-icon :name="$genderKey" class="h-5 w-5" />
                                    </span>
                                    <span class="arena-choice-title">{{ $genderLabel }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- 3. Subclase --}}
                <fieldset class="arena-wizard-step arena-animate-in arena-stagger-3" data-done="1">
                    <legend class="flex items-center gap-3 px-1">
                        <span class="arena-wizard-num">3</span>
                        <span class="text-base font-semibold text-white">Elige tu subclase</span>
                    </legend>
                    <p class="mb-3 mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        Decide cómo peleas. Tampoco se puede cambiar después.
                    </p>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        @foreach($subclasses as $key => $label)
                            <label class="arena-choice">
                                <input type="radio" name="subclass" value="{{ $key }}" required
                                       data-preview-input="subclass"
                                       data-label="{{ $label }}"
                                       @checked($oldSubclass === $key)>
                                <span class="arena-choice-body arena-choice-body-row">
                                    <span class="arena-choice-mark">
                                        <x-arena-icon :name="$key" class="h-5 w-5" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="arena-choice-title">{{ $label }}</span>
                                        <span class="arena-choice-note">{{ $subclassNotes[$key] ?? '' }}</span>
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-[color:var(--arena-muted)] arena-body-text">
                        Si eliges conjurador, el rol (soporte u ofensivo) se declara al entrar a la cola, no aquí.
                    </p>
                </fieldset>

                {{-- 4. Nombre --}}
                <fieldset class="arena-wizard-step arena-animate-in arena-stagger-4">
                    <legend class="flex items-center gap-3 px-1">
                        <span class="arena-wizard-num">4</span>
                        <span class="text-base font-semibold text-white">Ponle nombre</span>
                    </legend>
                    <p class="mb-3 mt-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                        Usa el mismo nombre que tu personaje dentro del juego: es como te reconocen los rivales
                        para confirmar los resultados.
                    </p>
                    <label class="block">
                        <span class="sr-only">Nombre del personaje</span>
                        <input type="text"
                               name="character_name"
                               value="{{ old('character_name') }}"
                               class="arena-field"
                               placeholder="Ej: SarKhan4651"
                               minlength="3"
                               maxlength="25"
                               data-preview-input="name"
                               required>
                    </label>
                    <p class="mt-2 text-xs text-[color:var(--arena-muted)] arena-body-text">
                        Entre 3 y 25 caracteres. Letras, números, espacios, guiones y guiones bajos.
                    </p>
                </fieldset>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="arena-btn px-6 py-3">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
                        Crear guerrero
                    </button>
                    <a href="{{ route('lobby') }}" class="arena-btn-ghost px-6 py-3">Cancelar</a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    /* La vista previa sigue a los campos: reino, raza, sexo y subclase
       reconstruyen el guerrero, y el nombre se escribe encima del escenario
       segun se teclea. Sin JavaScript el formulario funciona igual: se ven
       todas las razas y el servidor rechaza la que no case con el reino. */
    (function () {
        var form = document.getElementById('createChampionForm');
        if (!form) { return; }

        var host = document.querySelector('[data-champion-id="create-preview"]');
        var nameOut = document.querySelector('[data-preview-name]');
        var realmOut = document.querySelector('[data-preview-realm]');
        var raceOut = document.querySelector('[data-preview-race]');
        var subclassOut = document.querySelector('[data-preview-subclass]');

        function checked(name) {
            return form.querySelector('input[name="' + name + '"]:checked');
        }

        /* Cada reino tiene sus razas. Al cambiar de reino se ocultan las que no
           tocan y, si la que estaba elegida era de otro reino, se pasa a la
           primera del nuevo (siempre la variante humana). */
        function syncRaces(realm) {
            var options = form.querySelectorAll('[data-race-of]');

            // Marca el paso como filtrado: con las razas de un solo reino a la
            // vista sobra decir a que reino pertenecen.
            var step = form.querySelector('[data-race-options]').closest('.arena-wizard-step');
            if (step) { step.dataset.racesFiltered = '1'; }
            var current = checked('race');
            var firstOfRealm = null;

            options.forEach(function (option) {
                var belongs = option.dataset.raceOf === realm;
                option.hidden = !belongs;

                var input = option.querySelector('input');
                // Un radio oculto no debe seguir siendo obligatorio ni enviable.
                input.disabled = !belongs;

                if (belongs && !firstOfRealm) { firstOfRealm = input; }
                if (!belongs && input.checked) { input.checked = false; }
            });

            if (firstOfRealm && (!current || current.disabled || !current.checked)) {
                firstOfRealm.checked = true;
            }
        }

        function refresh() {
            var realm = checked('realm');
            if (realm) { syncRaces(realm.value); }

            var race = checked('race');
            var gender = checked('gender');
            var subclass = checked('subclass');
            var name = form.querySelector('input[name="character_name"]');

            if (realm) {
                realmOut.textContent = realm.dataset.label;
                if (host) { host.dataset.championRealm = realm.value; }
            }
            if (race) { raceOut.textContent = race.dataset.label; }
            if (subclass) { subclassOut.textContent = subclass.dataset.label; }

            nameOut.textContent = (name && name.value.trim()) ? name.value.trim() : 'Tu guerrero';

            var viewer = window.arenaChampionViewers && window.arenaChampionViewers['create-preview'];
            if (viewer && realm && subclass) {
                viewer.set(realm.value, subclass.value, race ? race.value : null, gender ? gender.value : 'male');
            }

            // El paso queda marcado como resuelto en cuanto tiene respuesta.
            form.querySelectorAll('.arena-wizard-step').forEach(function (step) {
                var text = step.querySelector('input[type="text"]');
                var done = text
                    ? text.value.trim().length >= 3
                    : step.querySelectorAll('input[type="radio"]:checked').length > 0;
                step.dataset.done = done ? '1' : '0';
            });
        }

        form.addEventListener('change', refresh);
        form.addEventListener('input', refresh);
        document.addEventListener('arena:champions-ready', refresh);
        refresh();
    })();
</script>
@endpush
