<div class="space-y-3" aria-live="polite">
    @if (session('success'))
        <div class="rounded-2xl border border-lime/70 bg-lime/20 px-4 py-3 text-sm font-medium">{{ session('success') }}</div>
    @endif

    @if (session('info'))
        <div class="rounded-2xl border border-orange/30 bg-orange/10 px-4 py-3 text-sm font-medium">{{ session('info') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-bold">Перевірте додані дані:</p>
            <ul class="mt-1 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
