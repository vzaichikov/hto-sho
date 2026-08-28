@php
    $formValues = $form ?? [];
    $step = $initialStep ?? ($errors->hasAny(['description', 'budget_amount']) || isset($failureMessage) ? 2 : 1);
    $titleValue = old('title', $formValues['title'] ?? '');
    $descriptionValue = old('description', $formValues['description'] ?? '');
    $budgetAmountValue = old('budget_amount', $formValues['budget_amount'] ?? '');
    $alcoholPlannedValue = (bool) old('alcohol_planned', $formValues['alcohol_planned'] ?? false);
@endphp

<x-layouts.app title="Нова подія">
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-10">
        <a class="inline-flex -rotate-1 items-center gap-2 rounded-sm bg-yellow/60 px-3 py-1.5 font-display text-lg transition hover:rotate-0 hover:bg-yellow" href="{{ route('events.index') }}">
            <span aria-hidden="true">←</span> До всіх подій
        </a>

        <div class="mt-6 grid overflow-hidden rounded-[32px] border-2 border-ink bg-paper shadow-[7px_8px_0_#20201D] lg:grid-cols-[minmax(0,1fr)_20rem]">
            <section class="p-5 sm:p-8 lg:p-10">
                <div class="flex items-center gap-3" aria-label="Прогрес створення події">
                    <span class="grid size-9 place-items-center rounded-full border-2 border-ink bg-orange text-sm font-extrabold text-white" data-create-step-indicator="1" aria-current="step">1</span>
                    <span class="h-1 w-12 rounded-full bg-ink/10 sm:w-20" data-create-step-line></span>
                    <span class="grid size-9 place-items-center rounded-full border-2 border-ink/25 bg-canvas text-sm font-extrabold text-muted" data-create-step-indicator="2">2</span>
                    <span class="ml-1 text-xs font-extrabold uppercase tracking-[0.14em] text-muted" data-create-step-label>Крок {{ $step }} з 2</span>
                </div>

                @if (isset($failureMessage))
                    <div class="mt-6 rounded-2xl border border-orange/30 bg-orange/10 px-4 py-3 text-sm font-semibold text-orange-dark" role="alert" data-create-server-error>
                        {{ $failureMessage }}
                    </div>
                @endif

                <form
                    class="mt-8"
                    method="POST"
                    action="{{ route('events.store') }}"
                    data-event-create
                    data-initial-step="{{ $step }}"
                    novalidate
                >
                    @csrf

                    <div data-create-step="1">
                        <p class="font-display text-lg leading-[1.15] text-green-dark">Спершу дамо пригоді імʼя</p>
                        <h1 class="mt-2 font-display text-4xl leading-[1.1] tracking-[-0.035em] sm:text-5xl">Як назвемо цей двіж?</h1>
                        <p class="mt-4 max-w-xl text-sm leading-6 text-muted sm:text-base">Коротко й упізнавано — щоб потім не шукати «оту штуку десь біля шашликів».</p>

                        <label class="mt-7 block text-sm font-extrabold" for="event-title">Назва події</label>
                        <input
                            class="mt-2 w-full rounded-2xl border-2 border-ink/15 bg-canvas px-4 py-4 text-base font-bold outline-none transition placeholder:text-muted/55 focus:border-green focus:ring-4 focus:ring-green/15"
                            id="event-title"
                            name="title"
                            maxlength="120"
                            placeholder="Наприклад, День народження Олі"
                            required
                            value="{{ $titleValue }}"
                            aria-describedby="event-title-error"
                            data-create-title
                        >
                        <p class="mt-2 min-h-5 text-sm font-semibold text-orange-dark" id="event-title-error" data-create-error="title">{{ $errors->first('title') }}</p>

                        <button class="mt-6 inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark sm:w-auto" type="button" data-create-next>
                            Є назва — далі <span class="text-yellow" aria-hidden="true">→</span>
                        </button>
                    </div>

                    <div class="mt-10 border-t border-ink/10 pt-8" data-create-step="2">
                        <p class="font-display text-lg leading-[1.15] text-green-dark">Тепер Гусь слухає</p>
                        <h2 class="mt-2 font-display text-4xl leading-[1.1] tracking-[-0.035em] sm:text-5xl">Киньте Гусю короткий задум</h2>
                        <p class="mt-4 max-w-xl text-sm leading-6 text-muted sm:text-base">Пікнік, шашлик, просто випити чи хочете щось нове — одного-двох речень досить. Анкету на сорок питань не заводимо.</p>

                        <div class="mt-5 flex flex-wrap gap-2" aria-label="Приклади опису">
                            @foreach (['пікнік на озері', 'шашлик у лісі', 'будемо просто бухати', 'хочемо щось нове від Гуся'] as $example)
                                <button class="rounded-full border border-ink/15 bg-yellow/30 px-3 py-2 text-xs font-extrabold transition hover:border-ink/35 hover:bg-yellow/60" type="button" data-create-example="{{ $example }}">{{ $example }}</button>
                            @endforeach
                        </div>

                        <label class="mt-6 block text-sm font-extrabold" for="event-description">Що задумали?</label>
                        <textarea
                            class="mt-2 min-h-36 w-full resize-y rounded-2xl border-2 border-ink/15 bg-canvas px-4 py-4 text-base leading-7 outline-none transition placeholder:text-muted/55 focus:border-green focus:ring-4 focus:ring-green/15"
                            id="event-description"
                            name="description"
                            maxlength="500"
                            placeholder="Наприклад: хочемо пікнік біля води, без складної готовки й з чимось новеньким"
                            required
                            aria-describedby="event-description-help event-description-error"
                            data-create-description
                        >{{ $descriptionValue }}</textarea>
                        <div class="mt-2 flex items-start justify-between gap-4">
                            <p class="text-xs leading-5 text-muted" id="event-description-help">Гусь перевірить лише доречність задуму. Меню й кількості не вигадуватиме.</p>
                            <p class="shrink-0 text-xs font-bold text-muted"><span data-create-description-count>{{ mb_strlen($descriptionValue) }}</span>/500</p>
                        </div>
                        <p class="mt-2 min-h-5 text-sm font-semibold text-orange-dark" id="event-description-error" data-create-error="description">{{ $errors->first('description') }}</p>

                        <div class="mt-5">
                            <div class="flex items-baseline justify-between gap-3">
                                <label class="block text-sm font-extrabold" for="event-budget-amount">Бюджет, ₴</label>
                                <span class="text-xs font-bold text-muted">необовʼязково</span>
                            </div>
                            <input
                                class="mt-2 w-full rounded-2xl border-2 border-ink/15 bg-canvas px-4 py-4 text-base font-bold outline-none transition placeholder:text-muted/55 focus:border-green focus:ring-4 focus:ring-green/15"
                                id="event-budget-amount"
                                name="budget_amount"
                                type="number"
                                min="0"
                                max="9999999999.99"
                                step="0.01"
                                inputmode="decimal"
                                placeholder="Наприклад, 3000"
                                value="{{ $budgetAmountValue }}"
                                aria-describedby="event-budget-help event-budget-error"
                                data-create-budget
                            >
                            <p class="mt-2 text-xs leading-5 text-muted" id="event-budget-help">Якщо ще не вирішили — лишайте порожнім. Гусь не домалює суму зі стелі.</p>
                            <p class="mt-2 min-h-5 text-sm font-semibold text-orange-dark" id="event-budget-error" data-create-error="budget_amount">{{ $errors->first('budget_amount') }}</p>
                        </div>

                        <div class="mt-5 rounded-2xl border-2 border-ink/15 bg-yellow/20 p-4 transition focus-within:border-green focus-within:ring-4 focus-within:ring-green/15">
                            <input type="hidden" name="alcohol_planned" value="0">
                            <label class="flex cursor-pointer items-start gap-3" for="event-alcohol-planned">
                                <input
                                    class="mt-0.5 size-5 shrink-0 accent-green focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green"
                                    id="event-alcohol-planned"
                                    name="alcohol_planned"
                                    type="checkbox"
                                    value="1"
                                    @checked($alcoholPlannedValue)
                                    aria-describedby="event-alcohol-warning"
                                    data-create-alcohol-planned
                                >
                                <span class="text-sm font-extrabold leading-6">Мені є 18 років, і ми будемо пити алкоголь.</span>
                            </label>
                            <p class="mt-3 border-t border-ink/10 pt-3 text-xs font-semibold leading-5 text-orange-dark" id="event-alcohol-warning">Гусь попереджає, що надмірне вживання алкоголю шкідливе для вашого здоровʼя.</p>
                        </div>

                        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                            <button class="rounded-2xl border-2 border-ink bg-paper px-5 py-3.5 text-sm font-extrabold transition hover:-translate-y-0.5 hover:bg-yellow/30" type="button" data-create-back>← Назад до назви</button>
                            <button class="inline-flex items-center justify-center gap-3 rounded-2xl bg-green px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition hover:-translate-y-0.5 hover:bg-green-dark disabled:cursor-wait disabled:opacity-60" type="submit" data-create-submit>
                                <span data-create-submit-label>Гусь, перевір задум</span>
                                <span class="text-yellow" aria-hidden="true">→</span>
                            </button>
                        </div>
                    </div>

                    <div class="rounded-[26px] border-2 border-green bg-green-soft/40 p-5" data-create-checking hidden aria-live="polite">
                        <div class="flex items-center gap-4">
                            <img class="goose-working size-20 shrink-0 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="Гусь Шо перевіряє задум">
                            <div>
                                <p class="font-display text-2xl leading-tight">Гусь принюхується до плану…</p>
                                <p class="mt-1 text-sm leading-6 text-muted">Нічого ще не зберігаємо. Спершу переконаємося, що тут є смачна пригода.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 hidden rounded-2xl border border-orange/30 bg-orange/10 px-4 py-3 text-sm font-semibold text-orange-dark" role="alert" aria-live="assertive" data-create-request-error></div>
                </form>
            </section>

            <aside class="relative min-h-72 overflow-hidden border-t-2 border-ink bg-yellow/35 p-6 lg:min-h-full lg:border-l-2 lg:border-t-0 lg:p-7">
                <span class="inline-block -rotate-2 rounded-sm bg-paper px-3 py-1 font-display text-lg">Без допиту з пристрастю</span>
                <p class="mt-5 max-w-64 text-sm font-semibold leading-6">Назва — щоб упізнати подію. Задум — щоб Гусь розумів, у який бік клювати список.</p>
                <p class="mt-4 max-w-60 text-xs leading-5 text-muted">Людей, алергії й домовленості можна додати пізніше з чату. Невідоме так і лишиться невідомим — без магічних цифр зі стелі.</p>
                <span class="absolute right-7 top-6 rotate-12 font-display text-4xl text-green" aria-hidden="true">♡</span>
                <img class="absolute -bottom-24 right-0 w-48 drop-shadow-xl sm:right-8 sm:w-56 lg:-bottom-20 lg:-right-8 lg:w-64" src="{{ asset('images/brand/goose-sho.png') }}" alt="" aria-hidden="true">
            </aside>
        </div>
    </div>
</x-layouts.app>
