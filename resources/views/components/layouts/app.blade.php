@props(['title' => config('app.name')])

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
    <div class="pointer-events-none fixed inset-x-0 top-0 -z-10 h-80 bg-[radial-gradient(circle_at_top_left,rgba(205,255,65,0.3),transparent_48%),radial-gradient(circle_at_top_right,rgba(255,125,61,0.18),transparent_38%)]"></div>

    @auth
        <header class="border-b border-ink/10 bg-canvas/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
                <x-logo-mark />

                <div class="flex items-center gap-3">
                    <span class="hidden max-w-48 truncate text-sm text-muted sm:block">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full border border-ink/15 px-4 py-2 text-sm font-semibold transition hover:border-ink/40 hover:bg-white" type="submit">
                            Вийти
                        </button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    <main>
        {{ $slot }}
    </main>
</body>
</html>
