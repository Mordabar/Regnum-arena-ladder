@extends('layouts.arena')

@section('title', 'Admin Access - Regnum Arena Ladder')

@section('content')
<div class="mx-auto max-w-lg px-4 py-16">
    <section class="arena-panel-strong p-8 relative overflow-hidden arena-animate-in">
        {{-- Decorative glow --}}
        <div class="absolute -top-20 left-1/2 -translate-x-1/2 w-60 h-60 rounded-full bg-[radial-gradient(circle,rgba(216,177,92,0.1),transparent_70%)] pointer-events-none"></div>

        <div class="relative text-center">
            <x-arena-brand class="mx-auto mb-6" />
            <p class="arena-kicker">Acceso protegido</p>
            <h1 class="mt-3 text-3xl font-bold text-[color:var(--arena-gold-soft)]">Panel administrativo</h1>
            <p class="mt-3 text-sm text-[color:var(--arena-muted)] arena-body-text">
                Sesión separada del login Discord. CSRF y rate limiting activos.
            </p>
        </div>

        <div class="relative mt-6 space-y-3">
            <div class="arena-card px-4 py-3 text-sm text-[color:var(--arena-sand)] arena-body-text">
                URL: <span class="font-mono text-white">/{{ $adminPath }}</span>
            </div>
            <div class="rounded-2xl border border-amber-500/20 bg-amber-950/20 px-4 py-3 text-sm text-amber-100 arena-body-text">
                Usuario por defecto: <span class="font-mono text-white">admin</span> · Contraseña sensible a mayúsculas.
            </div>
        </div>

        <form method="POST" action="{{ route('admin.login.attempt') }}" class="relative mt-6 space-y-5">
            @csrf
            <label class="block">
                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Usuario</span>
                <input id="adminUsername" type="text" name="username" value="{{ old('username', 'admin') }}" class="arena-field" autocomplete="username" required>
            </label>
            <label class="block">
                <span class="mb-2 block text-sm font-medium text-[color:var(--arena-text)] arena-body-text">Contraseña</span>
                <input id="adminPassword" type="password" name="password" class="arena-field" autocomplete="current-password" required>
            </label>
            <button type="submit" class="arena-btn w-full">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                Entrar al panel
            </button>
        </form>
    </section>
</div>
@endsection
