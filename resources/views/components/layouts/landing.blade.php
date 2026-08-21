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
    <title>{{ $title }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Neucha&display=swap" rel="stylesheet">
    @vite('resources/css/landing.css')
</head>
<body>
    {{ $slot }}
</body>
</html>
