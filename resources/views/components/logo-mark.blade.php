<a class="group inline-flex items-center gap-3" href="{{ auth()->check() ? route('events.index') : route('landing') }}" aria-label="Хто шо? — події">
    <span class="grid -rotate-3 font-display text-[27px] leading-[0.78] tracking-[-0.08em] transition group-hover:rotate-0">
        <span>ХТО</span>
        <span>ШО<i class="ml-0.5 text-[1.35em] not-italic text-orange">?</i></span>
    </span>
    <span class="hidden font-display text-[15px] leading-[1.1] text-muted lg:block">розгрібаємо<br>чат</span>
</a>
