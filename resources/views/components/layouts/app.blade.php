@props(['title' => config('app.brand_name')])

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FFF4DC">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Хто Шо?">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/pwa/icon-192.png') }}" sizes="192x192">
    <link rel="icon" type="image/png" href="{{ asset('images/pwa/icon-512.png') }}" sizes="512x512">
    <link rel="apple-touch-icon" href="{{ asset('images/pwa/apple-touch-icon.png') }}">
    <title>{{ $title }} — {{ config('app.brand_name') }}</title>
    @fonts('manrope')
    @vite(['resources/css/app.css', 'resources/js/pwa.js', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased" data-brand-app-shell>
    <x-pwa-install-banner />

    @auth
        <header class="sticky top-0 z-40 border-b-2 border-ink/10 bg-canvas/90 backdrop-blur-md">
            <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
                <div class="flex items-center gap-7">
                    <x-logo-mark />
                    <a class="hidden rounded-full border border-ink/10 bg-paper px-4 py-2 text-xs font-extrabold transition hover:border-ink/30 hover:bg-yellow/30 sm:inline-flex" href="{{ route('events.index') }}">
                        Мої події
                    </a>
                </div>

                <div class="flex items-center gap-2 sm:gap-3">
                    <span class="hidden max-w-52 truncate rounded-full bg-paper px-4 py-2 text-xs font-bold text-muted ring-1 ring-ink/10 sm:block">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-full border-2 border-ink bg-paper px-4 py-2 text-xs font-extrabold shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow/30" type="submit">
                            Вийти
                        </button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    <main class="relative">
        {{ $slot }}
    </main>
</body>
</html>
