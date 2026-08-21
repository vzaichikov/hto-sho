<a class="group inline-flex items-center gap-2.5" href="{{ auth()->check() ? route('events.index') : route('landing') }}">
    <span class="grid size-10 rotate-[-4deg] place-items-center rounded-[14px] bg-ink text-xl font-bold text-lime shadow-[3px_3px_0_#ff7d3d] transition group-hover:rotate-0">?</span>
    <span class="text-lg font-bold tracking-[-0.03em]">Хто Шо?</span>
</a>
