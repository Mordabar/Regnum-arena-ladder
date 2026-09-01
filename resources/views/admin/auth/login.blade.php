<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso · Arena Ladder</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) ?: '1' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
</head>
<body>
<main class="ap-login">
    <section class="ap-card ap-rise p-6" style="width: 100%; max-width: 380px">
        <div class="flex items-center gap-2.5 mb-5">
            <span class="ap-brand-mark" aria-hidden="true">RA</span>
            <span class="ap-brand-text">
                <strong>Arena Ladder</strong>
                <span>Panel de control</span>
            </span>
        </div>

        <h1 class="ap-section-title" style="font-size: 15px">Entrar al panel</h1>
        <p class="ap-section-note mb-4">
            Esta sesion es independiente de la de Discord. Tras varios intentos fallidos
            el acceso se bloquea un rato.
        </p>

        @if($errors->any())
            <div class="ap-flash ap-flash-danger" role="alert">
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        @if(session('success'))
            <div class="ap-flash ap-flash-ok" role="status">
                <span>{{ session('success') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.attempt') }}" class="flex flex-col gap-3">
            @csrf
            <div class="ap-field">
                <label class="ap-label" for="adminUsername">Usuario</label>
                <input id="adminUsername" type="text" name="username" value="{{ old('username') }}"
                       class="ap-input" autocomplete="username" autofocus required>
            </div>
            <div class="ap-field">
                <label class="ap-label" for="adminPassword">Contrasena</label>
                <input id="adminPassword" type="password" name="password" class="ap-input"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="ap-btn ap-btn-primary ap-btn-block mt-1">Entrar</button>
        </form>
    </section>
</main>
</body>
</html>
