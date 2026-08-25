@props([
    'title' => 'Хто шо? — розгребемо чат, зберемо кошик',
    'description' => 'Хто шо? — перетворюємо хаос у чаті на готовий кошик Сільпо.',
])

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description }}">
    <meta name="theme-color" content="#FFF4DC">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Хто Шо?">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48 64x64">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon-goose.png') }}" sizes="512x512">
    <link rel="icon" type="image/png" href="{{ asset('images/pwa/icon-192.png') }}" sizes="192x192">
    <link rel="icon" type="image/png" href="{{ asset('images/pwa/icon-512.png') }}" sizes="512x512">
    <link rel="apple-touch-icon" href="{{ asset('images/pwa/apple-touch-icon.png') }}">
    <title>{{ $title }}</title>
    @fonts('manrope')
    @vite(['resources/css/landing.css', 'resources/js/pwa.js'])
</head>
<body>
    <x-pwa-install-banner />

    {{ $slot }}
</body>
</html>
