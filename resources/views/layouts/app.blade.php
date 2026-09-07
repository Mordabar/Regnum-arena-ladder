<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Regnum Arena Ladder')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Utilidades compiladas en el propio dominio, no desde un CDN externo. --}}
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ @filemtime(public_path('css/site.css')) ?: '1' }}">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        }
    </style>
</head>
<body class="min-h-screen text-white">
    <nav class="bg-black bg-opacity-50 backdrop-blur-sm border-b border-purple-500/30">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <a href="/" class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-600">
                        🏰 Regnum Arena Ladder
                    </a>
                </div>
                <div class="flex items-center space-x-6">
                    @auth
                        <div class="flex items-center space-x-4">
                            <span class="text-gray-300">¡Hola {{ auth()->user()->discord_username }}!</span>
                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" 
                                        class="bg-gradient-to-r from-red-600 to-pink-600 hover:from-red-700 hover:to-pink-700 px-4 py-2 rounded-lg font-semibold transition-all transform hover:scale-105">
                                    🚪 Salir
                                </button>
                            </form>
                        </div>
                    @else
                        <a href="{{ route('auth.discord') }}" 
                           class="bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 px-6 py-2 rounded-lg font-semibold transition-all transform hover:scale-105">
                            🔗 Discord
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <main>
        @yield('content')
    </main>
</body>
</html>