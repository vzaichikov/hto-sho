@php
    $state = $event->state ?? [];
    $participants = $state['participants'] ?? [];
    $restrictions = $state['restrictions'] ?? [];
    $agreements = $state['agreements'] ?? [];
    $warnings = $state['warnings'] ?? [];
    $questions = $state['unresolved_questions'] ?? [];
    $participantStatusLabels = [
        'confirmed' => 'Буде',
        'declined' => 'Не буде',
        'uncertain' => 'Ще думає',
        'unknown' => 'Невідомо',
    ];
    $analysisActive = $event->hasActiveAnalysis();
    $analysisProgress = $event->analysis_stage?->progress() ?? 0;
@endphp

<x-layouts.app :title="$event->title">
    <div
        class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10"
        data-event-workspace
        data-event-status-url="{{ route('events.status', $event) }}"
        data-event-state-version="{{ $event->state_version }}"
    >
        <a class="inline-flex -rotate-1 items-center gap-2 rounded-sm bg-yellow/60 px-3 py-1.5 font-display text-lg transition hover:rotate-0 hover:bg-yellow" href="{{ route('events.index') }}">
            <span aria-hidden="true">←</span> До всіх подій
        </a>

        <x-flash-messages class="mt-4" />

        <header class="mt-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="truncate font-display text-4xl leading-none tracking-[-0.035em] sm:text-5xl">{{ $event->title }}</h1>
                    <x-status-badge :status="$event->status" data-event-status-badge />
                    @if ($event->hasUnanalyzedChanges() && $event->state !== null)
                        <span class="rounded-full bg-yellow px-3 py-1 text-xs font-extrabold text-ink" data-stale-badge>Є нові дані</span>
                    @endif
                </div>
                <p class="mt-2 text-sm text-muted">
                    Контекст v{{ $event->state_version }} · докази r{{ $event->evidence_version }} · {{ $event->sources->count() }} джерел
                </p>
            </div>

            <details class="relative shrink-0">
                <summary class="cursor-pointer list-none rounded-full border-2 border-ink bg-paper px-4 py-2 text-sm font-extrabold shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow/30">Налаштування</summary>
                <div class="absolute right-0 z-30 mt-3 w-[min(22rem,calc(100vw-2rem))] rounded-2xl border-2 border-ink bg-paper p-4 shadow-[5px_6px_0_#20201D]">
                    <form method="POST" action="{{ route('events.update', $event) }}">
                        @csrf
                        @method('PATCH')
                        <label class="text-xs font-bold text-muted" for="title">Назва події</label>
                        <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="title" name="title" maxlength="120" required value="{{ $event->title }}">
                        <label class="mt-4 block text-xs font-bold text-muted" for="description">Короткий опис</label>
                        <textarea class="mt-2 min-h-20 w-full resize-y rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm outline-none focus:border-green focus:ring-3 focus:ring-green/15" id="description" name="description" maxlength="1000">{{ $event->description }}</textarea>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-muted" for="people_count">Людей</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm" id="people_count" name="people_count" type="number" min="1" max="10000" value="{{ $event->people_count }}">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-muted" for="budget_amount">Бюджет, ₴</label>
                                <input class="mt-2 w-full rounded-xl border border-ink/20 bg-canvas px-3 py-2.5 text-sm" id="budget_amount" name="budget_amount" type="number" min="0" step="0.01" value="{{ $event->budget_amount }}">
                            </div>
                        </div>
                        <button class="mt-4 w-full rounded-xl bg-orange px-4 py-2.5 text-sm font-extrabold text-white hover:bg-orange-dark" type="submit">Зберегти</button>
                    </form>

                    <form class="mt-4 border-t border-ink/10 pt-4" method="POST" action="{{ route('events.destroy', $event) }}" data-confirm="Видалити подію разом з усіма джерелами?">
                        @csrf
                        @method('DELETE')
                        <button class="text-sm font-bold text-orange-dark hover:text-beet" type="submit">Видалити подію</button>
                    </form>
                </div>
            </details>
        </header>

        <section class="mt-8 overflow-hidden rounded-[30px] border-2 border-ink bg-paper shadow-[7px_8px_0_#20201D]" id="composer">
            <div class="relative min-h-28 overflow-hidden border-b-2 border-ink/10 bg-yellow/35 px-5 py-5 pr-28 sm:px-7 sm:pr-40">
                <p class="font-display text-lg leading-none text-green-dark">Гусь чекає на новини</p>
                <h2 class="mt-1 font-display text-3xl leading-none">Додавайте контекст як заманеться</h2>
                <p class="mt-2 text-xs leading-5 text-muted sm:text-sm">Текст збережемо одразу. Кожну картинку окремо роздивиться фоновий job.</p>
                <img class="absolute -bottom-24 right-2 w-28 drop-shadow-lg sm:-bottom-32 sm:right-6 sm:w-40" src="{{ asset('images/brand/goose-sho.png') }}" alt="" aria-hidden="true">
            </div>

            <form class="p-5 sm:p-7" method="POST" action="{{ route('events.sources.store', $event) }}" enctype="multipart/form-data" data-source-composer>
                @csrf
                <label class="sr-only" for="source-text">Текст переписки або уточнення</label>
                <textarea class="min-h-36 w-full resize-y rounded-2xl border border-ink/20 bg-canvas p-4 text-base leading-7 outline-none placeholder:text-muted/70 focus:border-green focus:ring-4 focus:ring-green/15" id="source-text" name="text" maxlength="50000" placeholder="Наприклад: Марина не прийде, Саша бере вугілля, а в Олі алергія на арахіс…">{{ old('text') }}</textarea>

                <div class="mt-4 rounded-2xl border-2 border-dashed border-green/45 bg-green-soft/20 p-5 text-center transition data-[dragging=true]:border-orange data-[dragging=true]:bg-orange/5" data-file-dropzone>
                    <input class="sr-only" id="source-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-file-input>
                    <label class="cursor-pointer" for="source-images">
                        <span class="mx-auto grid size-11 -rotate-3 place-items-center rounded-[45%] bg-yellow font-display text-2xl transition hover:rotate-0" aria-hidden="true">↥</span>
                        <span class="mt-3 block text-sm font-extrabold">Перетягніть, вставте або виберіть картинки</span>
                        <span class="mt-1 block text-xs text-muted">JPG, PNG чи WebP · до 8 МБ · максимум 10 файлів</span>
                    </label>
                    <div class="mt-4 hidden grid-cols-2 gap-3 text-left sm:grid-cols-4" data-file-previews></div>
                </div>

                <button class="mt-5 inline-flex w-full items-center justify-center rounded-2xl bg-orange px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-orange-dark sm:w-auto" type="submit">
                    Додати до історії <span class="ml-3 text-yellow" aria-hidden="true">→</span>
                </button>
            </form>
        </section>

        <section class="mt-7 rounded-[26px] border-2 border-green bg-green-soft/45 p-5 sm:flex sm:items-center sm:justify-between sm:gap-8 sm:p-6">
            <div>
                <p class="font-display text-2xl leading-tight text-green-dark">Коли все накидали — кличте Гуся</p>
                <p class="mt-1 max-w-2xl text-sm leading-6 text-muted">
                    Повний підсумок запускається тільки вручну. Якщо ви додасте чи видалите щось під час роботи, task дочекається п’яти секунд тиші та почне синтез заново.
                </p>
            </div>
            <form class="mt-4 shrink-0 sm:mt-0" method="POST" action="{{ route('events.analysis.store', $event) }}" data-analysis-form>
                @csrf
                <button class="w-full rounded-2xl bg-green px-6 py-4 font-extrabold text-white shadow-[4px_4px_0_#20201D] transition hover:-translate-y-0.5 hover:bg-green-dark disabled:cursor-wait disabled:opacity-55" type="submit" data-analysis-button @disabled($analysisActive)>
                    {{ $analysisActive ? 'Гусь уже гребе…' : 'Гусь, розгреби все' }}
                </button>
            </form>
        </section>

        @if ($event->analysis_stage === \App\EventAnalysisStage::Failed)
            <section class="mt-6 rounded-[22px] border border-orange/40 bg-orange/10 p-5 text-orange-dark">
                <h2 class="font-bold">Гусь не впорався з full summary</h2>
                <p class="mt-1 text-sm">{{ $event->analysis_error }}</p>
            </section>
        @endif

        @if ($state !== [])
            <section class="mt-9 rounded-[30px] border-2 border-ink bg-paper p-5 shadow-[6px_7px_0_#F7C84B] sm:p-7" data-context-summary>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Спільний контекст</p>
                        <h2 class="mt-1 font-display text-3xl">Що Гусь зрозумів</h2>
                    </div>
                    @if ($event->hasUnanalyzedChanges())
                        <span class="rounded-full bg-yellow px-3 py-1 text-xs font-extrabold">Застарів, але ще корисний</span>
                    @endif
                </div>

                <p class="mt-5 text-base leading-7 text-muted">{{ $state['summary'] ?? 'Підсумок без короткого опису.' }}</p>

                @if ($warnings !== [])
                    <div class="mt-5 rounded-2xl border border-orange/30 bg-orange/8 p-4">
                        <p class="text-xs font-extrabold uppercase tracking-[0.12em] text-orange-dark">Обережно</p>
                        <ul class="mt-2 space-y-2 text-sm leading-6">
                            @foreach ($warnings as $warning)
                                <li>→ {{ is_array($warning) ? ($warning['message'] ?? '') : $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-6 grid gap-5 lg:grid-cols-2">
                    <div>
                        <h3 class="font-display text-2xl">Люди</h3>
                        <div class="mt-3 space-y-3">
                            @forelse ($participants as $participant)
                                <article class="rounded-2xl bg-canvas p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="font-extrabold">{{ $participant['name'] ?? 'Невідомий учасник' }}</p>
                                        <span class="rounded-full bg-paper px-2.5 py-1 text-xs font-bold text-muted">{{ $participantStatusLabels[$participant['status'] ?? 'unknown'] ?? 'Невідомо' }}</span>
                                    </div>
                                    @foreach (['preferences' => 'Хоче', 'restrictions' => 'Не можна', 'allergies' => 'Алергії', 'brings' => 'Бере'] as $key => $label)
                                        @if (($participant[$key] ?? []) !== [])
                                            <p class="mt-2 text-xs leading-5"><b>{{ $label }}:</b> {{ implode(', ', $participant[$key]) }}</p>
                                        @endif
                                    @endforeach
                                    @if (($participant['source_ids'] ?? []) !== [])
                                        <p class="mt-2 text-[11px] font-bold text-muted">Джерела: {{ implode(', ', array_map(fn ($id) => '#'.$id, $participant['source_ids'])) }}</p>
                                    @endif
                                </article>
                            @empty
                                <p class="rounded-2xl border border-dashed border-ink/15 p-4 text-sm text-muted">Імен поки не назбиралось.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="space-y-5">
                        @foreach ([['label' => 'Обмеження й алергії', 'items' => $restrictions, 'key' => 'restriction'], ['label' => 'Домовленості', 'items' => $agreements, 'key' => 'summary'], ['label' => 'Що ще спитати', 'items' => $questions, 'key' => 'question']] as $section)
                            @if ($section['items'] !== [])
                                <div>
                                    <h3 class="font-display text-2xl">{{ $section['label'] }}</h3>
                                    <ul class="mt-2 space-y-2 text-sm leading-6">
                                        @foreach ($section['items'] as $item)
                                            <li class="rounded-xl bg-canvas px-4 py-3">
                                                {{ is_array($item) ? ($item[$section['key']] ?? '') : $item }}
                                                @if (is_array($item) && ($item['source_ids'] ?? []) !== [])
                                                    <span class="ml-1 text-xs font-bold text-muted">{{ implode(', ', array_map(fn ($id) => '#'.$id, $item['source_ids'])) }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section class="mt-10" aria-labelledby="history-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-green-dark">Від старого до нового</p>
                    <h2 class="mt-1 font-display text-4xl" id="history-title">Історія контексту</h2>
                </div>
                <p class="text-sm text-muted">Кожен факт має адресу: source #ID</p>
            </div>

            <div class="mt-6 space-y-5" data-source-history>
                @forelse ($event->sources as $source)
                    @php $extraction = $source->imageExtraction; @endphp
                    <article class="relative rounded-[24px] border-2 border-ink/15 bg-paper p-4 shadow-[4px_4px_0_rgb(32_32_29_/_10%)] sm:p-5" data-source-card data-source-id="{{ $source->id }}" data-source-status="{{ $source->status->value }}">
                        <span class="absolute -left-2 -top-3 grid size-9 -rotate-3 place-items-center rounded-[45%] bg-yellow font-display text-lg shadow-sm">#{{ $source->id }}</span>

                        <div class="flex flex-wrap items-center justify-between gap-2 pl-7">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-extrabold uppercase tracking-[0.12em] text-muted">{{ $source->type === \App\EventSourceType::Image ? 'Зображення' : 'Текст' }}</span>
                                <span class="rounded-full bg-canvas px-2.5 py-1 text-xs font-bold" data-source-status-label>{{ $source->status->label() }}</span>
                                @if ($extraction?->classification)
                                    <span class="rounded-full bg-green-soft px-2.5 py-1 text-xs font-bold text-green-dark">{{ $extraction->classification->label() }}</span>
                                @endif
                                @if ($source->used_cached_extraction)
                                    <span class="rounded-full bg-yellow/45 px-2.5 py-1 text-xs font-bold">SHA-кеш</span>
                                @endif
                                @if ($source->inclusion === \App\EventSourceInclusion::Dismissed)
                                    <span class="rounded-full bg-ink/8 px-2.5 py-1 text-xs font-bold text-muted">Не входить у summary</span>
                                @elseif ($source->inclusion === \App\EventSourceInclusion::Forced)
                                    <span class="rounded-full bg-orange/12 px-2.5 py-1 text-xs font-bold text-orange-dark">Додано вручну</span>
                                @endif
                            </div>
                            <time class="text-xs text-muted" datetime="{{ $source->created_at->toISOString() }}">{{ $source->created_at->format('d.m.Y H:i:s') }}</time>
                        </div>

                        <div class="mt-4 grid gap-5 {{ $source->type === \App\EventSourceType::Image ? 'md:grid-cols-[minmax(0,19rem)_1fr]' : '' }}">
                            @if ($source->type === \App\EventSourceType::Image)
                                <a class="block overflow-hidden rounded-2xl bg-canvas" href="{{ route('events.sources.show', [$event, $source]) }}" target="_blank">
                                    <img class="max-h-[26rem] w-full object-contain" src="{{ route('events.sources.show', [$event, $source]) }}" alt="{{ $source->original_name ?: 'Додане зображення' }}" loading="lazy">
                                </a>
                            @endif

                            <div class="min-w-0">
                                @if ($source->type === \App\EventSourceType::Text)
                                    <p class="whitespace-pre-wrap text-sm leading-7">{{ $source->text }}</p>
                                @else
                                    @if (in_array($source->status, [\App\EventSourceStatus::Pending, \App\EventSourceStatus::Processing], true))
                                        @php $sourceProgress = $source->status === \App\EventSourceStatus::Pending ? 12 : 62; @endphp
                                        <div class="rounded-2xl bg-yellow/20 p-4" aria-live="polite">
                                            <p class="text-sm font-bold" data-source-message>{{ $source->status === \App\EventSourceStatus::Pending ? 'Ще одна картинка? Ну звісно. Давайте сюди.' : 'Гусь Шо дивиться на вашу картинку. Пильно. Трохи осудливо.' }}</p>
                                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink/10">
                                                <div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: {{ $sourceProgress }}%" data-source-progress></div>
                                            </div>
                                        </div>
                                    @endif

                                    @if ($source->status === \App\EventSourceStatus::Processed)
                                        @if ($extraction?->source_summary)
                                            <div>
                                                <p class="text-xs font-extrabold uppercase tracking-[0.12em] text-green-dark">Коротко</p>
                                                <p class="mt-1 text-sm leading-6">{{ $extraction->source_summary }}</p>
                                            </div>
                                        @endif
                                        @if ($extraction?->ocr_text)
                                            <details class="mt-4 rounded-2xl bg-canvas p-4">
                                                <summary class="cursor-pointer text-sm font-extrabold">Показати OCR</summary>
                                                <p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-muted">{{ $extraction->ocr_text }}</p>
                                            </details>
                                        @endif
                                        @if ($extraction?->classification === \App\ImageClassification::Irrelevant)
                                            <div class="mt-4 rounded-2xl border border-ink/10 bg-ink/4 p-4">
                                                <p class="text-sm font-bold">Гусь це відкинув</p>
                                                <p class="mt-1 text-sm leading-6 text-muted">{{ $extraction->dismissal_reason }}</p>
                                                <form class="mt-3" method="POST" action="{{ route('events.sources.inclusion', [$event, $source]) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="inclusion" value="{{ $source->inclusion === \App\EventSourceInclusion::Forced ? \App\EventSourceInclusion::Dismissed->value : \App\EventSourceInclusion::Forced->value }}">
                                                    <button class="text-sm font-extrabold text-orange-dark underline decoration-2 underline-offset-4" type="submit">
                                                        {{ $source->inclusion === \App\EventSourceInclusion::Forced ? 'Ні, Гусь мав рацію — відкинути' : 'Гусь, це все ж важливо' }}
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    @elseif ($source->status === \App\EventSourceStatus::Failed)
                                        <div class="rounded-2xl border border-orange/35 bg-orange/8 p-4">
                                            <p class="text-sm font-extrabold text-orange-dark">Не розібрав: {{ $source->processing_error }}</p>
                                            <form class="mt-3" method="POST" action="{{ route('events.sources.retry', [$event, $source]) }}">
                                                @csrf
                                                <button class="text-sm font-extrabold underline decoration-2 underline-offset-4" type="submit">Гусь, ще раз</button>
                                            </form>
                                        </div>
                                    @endif
                                @endif

                                <form class="mt-4 flex justify-end border-t border-ink/8 pt-3" method="POST" action="{{ route('events.sources.destroy', [$event, $source]) }}" data-confirm="Видалити source #{{ $source->id }} та його приватний файл?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs font-extrabold text-muted hover:text-orange-dark" type="submit">Видалити джерело</button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[26px] border-2 border-dashed border-green/45 bg-paper/60 px-6 py-12 text-center">
                        <p class="font-display text-4xl text-green-dark" aria-hidden="true">↑</p>
                        <h3 class="mt-3 font-display text-3xl">Тут ще тихо</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted">Люди непередбачувані. Додайте перше повідомлення, а потім ще одне — коли вони, звісно, передумають.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <aside class="fixed left-1/2 top-1/2 z-50 {{ $analysisActive ? '' : 'hidden' }} w-[min(24rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 rounded-[24px] border-2 border-ink bg-paper p-4 shadow-[6px_7px_0_#20201D]" data-analysis-overlay data-minimized="false" aria-live="polite">
        <div class="flex items-start gap-3">
            <img class="goose-working -ml-1 size-16 shrink-0 object-contain" src="{{ asset('images/brand/goose-sho.png') }}" alt="Гусь Шо працює">
            <div class="min-w-0 flex-1" data-analysis-details>
                <div class="flex items-start justify-between gap-2">
                    <p class="font-display text-xl leading-tight">Повний розгріб</p>
                    <button class="rounded-full bg-canvas px-2.5 py-1 text-xs font-bold" type="button" data-analysis-minimize aria-label="Згорнути прогрес">−</button>
                </div>
                <p class="mt-1 text-sm leading-5 text-muted" data-analysis-message>{{ $event->analysis_stage?->message() }}</p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink/10">
                    <div class="h-full rounded-full bg-orange transition-[width] duration-500" style="width: {{ $analysisProgress }}%" data-analysis-progress></div>
                </div>
                <p class="mt-2 text-right text-xs font-bold text-muted"><span data-analysis-progress-label>{{ $analysisProgress }}</span>% по-чесному</p>
            </div>
        </div>
    </aside>
</x-layouts.app>
