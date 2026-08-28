@extends('layouts.arena')

@section('title', 'Admin Testing Lab - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <section class="arena-panel-strong mb-8 p-6 md:p-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="arena-kicker">Testing aislado</p>
                <h1 class="mt-3 text-4xl font-bold text-white md:text-5xl">Laboratorio de pruebas</h1>
                <p class="mt-3 max-w-3xl text-[color:var(--arena-muted)]">
                    Aqui viven los bots y las pruebas operativas. Este espacio ya no se mezcla con la experiencia principal de `/queue`.
                </p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('admin.dashboard') }}" class="arena-btn-ghost">Volver al admin</a>
                <a href="{{ route('queue.index') }}" class="arena-btn-secondary">Ir a cola real</a>
            </div>
        </div>
    </section>

    @include('queue._sandbox', ['sandbox' => $sandbox])
</div>
@endsection
