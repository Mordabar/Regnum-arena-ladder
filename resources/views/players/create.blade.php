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
            Tres decisiones y ya estás en la arena. Vas viendo a tu guerrero mientras eliges:
            el reino le da el color y la subclase, el arma.
        </p>
        <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
            Slot {{ $visible + 1 }} de 5 · El reino y la subclase no se pueden cambiar después.
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

    <form method="POST" action="{{ route('player.register') }}" id="createChampionForm">
        @csrf
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_400px] items-start">

            {{-- ── VISTA PREVIA ── --}}
            <div class="lg:sticky lg:top-6 order-1">
                <x-arena-champion
                    id="create-preview"
                    :realm="$oldRealm"
                    :subclass="$oldSubclass"
                    height="clamp(320px, 44vh, 500px)"
                    class="arena-animate-in arena-stagger-1">
                    <div class="arena-champion-overlay">
                        <div class="absolute inset-x-5 bottom-5">
                            <h2 class="arena-champion-name" data-preview-name>Tu guerrero</h2>
                            <p class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-[color:var(--arena-muted)] arena-body-text">
                                <span class="arena-champion-realm" data-preview-realm>Ignis</span>
                                <span data-preview-subclass>Caballero</span>
                            </p>
                        </div>
                    </div>
                </x-arena-champion>

                <p class="mt-3 text-center text-xs text-[color:var(--arena-muted)] arena-body-text">
                    Vista previa. Los modelos definitivos irán sustituyendo a estas siluetas por reino y subclase.
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

                {{-- 2. Subclase --}}
                <fieldset class="arena-wizard-step arena-animate-in arena-stagger-3" data-done="1">
                    <legend class="flex items-center gap-3 px-1">
                        <span class="arena-wizard-num">2</span>
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
                                <span class="arena-choice-body" style="align-items: flex-start; text-align: left">
                                    <span class="arena-choice-title">{{ $label }}</span>
                                    <span class="arena-choice-note">{{ $subclassNotes[$key] ?? '' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-[color:var(--arena-muted)] arena-body-text">
                        Si eliges conjurador, el rol (soporte u ofensivo) se declara al entrar a la cola, no aquí.
                    </p>
                </fieldset>

                {{-- 3. Nombre --}}
                <fieldset class="arena-wizard-step arena-animate-in arena-stagger-4">
                    <legend class="flex items-center gap-3 px-1">
                        <span class="arena-wizard-num">3</span>
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
    /* La vista previa sigue a los campos: cada cambio de reino o subclase
       reconstruye el guerrero, y el nombre se escribe encima del escenario
       según se teclea. Sin JavaScript el formulario funciona igual, solo que
       sin previsualización. */
    (function () {
        var form = document.getElementById('createChampionForm');
        if (!form) { return; }

        var host = document.querySelector('[data-champion-id="create-preview"]');
        var nameOut = document.querySelector('[data-preview-name]');
        var realmOut = document.querySelector('[data-preview-realm]');
        var subclassOut = document.querySelector('[data-preview-subclass]');

        function checked(name) {
            return form.querySelector('input[name="' + name + '"]:checked');
        }

        function refresh() {
            var realm = checked('realm');
            var subclass = checked('subclass');
            var name = form.querySelector('input[name="character_name"]');

            if (realm) {
                realmOut.textContent = realm.dataset.label;
                if (host) { host.dataset.championRealm = realm.value; }
            }
            if (subclass) { subclassOut.textContent = subclass.dataset.label; }

            nameOut.textContent = (name && name.value.trim()) ? name.value.trim() : 'Tu guerrero';

            var viewer = window.arenaChampionViewers && window.arenaChampionViewers['create-preview'];
            if (viewer && realm && subclass) { viewer.set(realm.value, subclass.value); }

            // El paso queda marcado como resuelto en cuanto tiene respuesta.
            form.querySelectorAll('.arena-wizard-step').forEach(function (step) {
                var input = step.querySelector('input[type="radio"]:checked, input[type="text"]');
                var done = input && (input.type === 'radio' || input.value.trim().length >= 3);
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
