@if (session('success') || session('info') || session('error') || $errors->any())
    <div {{ $attributes->class(['space-y-3']) }} aria-live="polite" data-flash-messages>
        @if (session('success'))
            <div class="rounded-2xl border border-green/30 bg-green-soft/70 px-4 py-3 text-sm font-semibold text-green-dark">{{ session('success') }}</div>
        @endif

        @if (session('info'))
            <div class="rounded-2xl border border-yellow bg-yellow/30 px-4 py-3 text-sm font-semibold">{{ session('info') }}</div>
        @endif

        @if (session('error'))
            <div class="rounded-2xl border border-orange/30 bg-orange/10 px-4 py-3 text-sm font-semibold text-orange-dark">{{ session('error') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-orange/30 bg-orange/10 px-4 py-3 text-sm text-orange-dark">
                <p class="font-bold">Перевірте додані дані:</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
