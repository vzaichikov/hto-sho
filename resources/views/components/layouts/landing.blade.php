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
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48 64x64">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/favicon-goose.png') }}" sizes="512x512">
    <title>{{ $title }}</title>
    @fonts(['manrope', 'neucha'])
    @vite('resources/css/landing.css')
</head>
<body>
    {{ $slot }}
</body>
</html>
