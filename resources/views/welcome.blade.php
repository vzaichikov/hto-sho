<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-50 antialiased">
        <main class="flex min-h-screen items-center justify-center px-6 py-12">
            <section class="flex max-w-xl flex-col items-center gap-5 text-center">
                <p class="text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase">
                    Новий проєкт
                </p>

                <h1 class="text-5xl font-semibold tracking-tight sm:text-7xl">
                    Хто Шо?
                </h1>

                <p class="max-w-md text-base leading-7 text-zinc-400 sm:text-lg">
                    Готуємо основу. Скоро тут буде щось цікаве.
                </p>
            </section>
        </main>
    </body>
</html>
