const sourceComposer = document.querySelector('[data-source-composer]');

if (sourceComposer) {
    const input = sourceComposer.querySelector('[data-file-input]');
    const cameraInput = sourceComposer.querySelector('[data-camera-input]');
    const cameraTrigger = sourceComposer.querySelector('[data-camera-trigger]');
    const dropzone = sourceComposer.querySelector('[data-file-dropzone]');
    const previews = sourceComposer.querySelector('[data-file-previews]');

    const renderPreviews = () => {
        previews.replaceChildren();
        previews.classList.toggle('hidden', input.files.length === 0);
        previews.classList.toggle('grid', input.files.length > 0);

        Array.from(input.files).forEach((file) => {
            const preview = document.createElement('div');
            preview.className = 'overflow-hidden rounded-xl border border-ink/10 bg-white';

            const image = document.createElement('img');
            image.className = 'h-24 w-full object-cover';
            image.alt = '';
            image.src = URL.createObjectURL(file);
            image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });

            const name = document.createElement('p');
            name.className = 'truncate px-2.5 py-2 text-xs font-semibold';
            name.textContent = file.name;

            preview.append(image, name);
            previews.append(preview);
        });
    };

    const replaceFiles = (files) => {
        const transfer = new DataTransfer();

        Array.from(files)
            .filter((file) => file.type.startsWith('image/'))
            .slice(0, 10)
            .forEach((file) => transfer.items.add(file));

        input.files = transfer.files;
        renderPreviews();
    };

    input.addEventListener('change', () => replaceFiles(input.files));
    cameraTrigger?.addEventListener('click', () => cameraInput.click());
    cameraInput?.addEventListener('change', () => {
        replaceFiles([...input.files, ...cameraInput.files]);
        cameraInput.value = '';
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.dataset.dragging = 'true';
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.dataset.dragging = 'false';
        });
    });

    dropzone.addEventListener('drop', (event) => replaceFiles(event.dataTransfer.files));

    document.addEventListener('paste', (event) => {
        const pastedImages = Array.from(event.clipboardData?.files ?? [])
            .filter((file) => file.type.startsWith('image/'));

        if (pastedImages.length > 0) {
            replaceFiles([...input.files, ...pastedImages]);
        }
    });
}

const eventCreateForm = document.querySelector('[data-event-create]');

if (eventCreateForm) {
    const titleInput = eventCreateForm.querySelector('[data-create-title]');
    const descriptionInput = eventCreateForm.querySelector('[data-create-description]');
    const budgetInput = eventCreateForm.querySelector('[data-create-budget]');
    const stepPanels = Array.from(eventCreateForm.querySelectorAll('[data-create-step]'));
    const stepIndicators = Array.from(document.querySelectorAll('[data-create-step-indicator]'));
    const stepLine = document.querySelector('[data-create-step-line]');
    const stepLabel = document.querySelector('[data-create-step-label]');
    const checkingPanel = eventCreateForm.querySelector('[data-create-checking]');
    const submitButton = eventCreateForm.querySelector('[data-create-submit]');
    const requestError = eventCreateForm.querySelector('[data-create-request-error]');
    const serverError = document.querySelector('[data-create-server-error]');
    const descriptionCount = eventCreateForm.querySelector('[data-create-description-count]');
    const fieldInputs = {
        title: titleInput,
        description: descriptionInput,
        budget_amount: budgetInput,
    };
    let currentStep = Number(eventCreateForm.dataset.initialStep) === 2 ? 2 : 1;

    const fieldError = (field) => eventCreateForm.querySelector(`[data-create-error="${field}"]`);

    const setFieldError = (field, message = '') => {
        const error = fieldError(field);
        const input = fieldInputs[field];

        if (error) {
            error.textContent = message;
        }

        input?.toggleAttribute('aria-invalid', message !== '');
    };

    const setStep = (step) => {
        currentStep = step;
        checkingPanel.hidden = true;

        stepPanels.forEach((panel) => {
            panel.hidden = Number(panel.dataset.createStep) !== step;
        });
        stepIndicators.forEach((indicator) => {
            const indicatorStep = Number(indicator.dataset.createStepIndicator);
            const reached = indicatorStep <= step;
            indicator.classList.toggle('bg-orange', reached);
            indicator.classList.toggle('text-white', reached);
            indicator.classList.toggle('border-ink', reached);
            indicator.classList.toggle('bg-canvas', ! reached);
            indicator.classList.toggle('text-muted', ! reached);
            indicator.classList.toggle('border-ink/25', ! reached);

            if (indicatorStep === step) {
                indicator.setAttribute('aria-current', 'step');
            } else {
                indicator.removeAttribute('aria-current');
            }
        });
        stepLine?.classList.toggle('bg-green', step === 2);
        stepLine?.classList.toggle('bg-ink/10', step !== 2);

        if (stepLabel) {
            stepLabel.textContent = `Крок ${step} з 2`;
        }
    };

    const validateTitle = () => {
        titleInput.value = titleInput.value.trim();

        if (titleInput.value === '') {
            setFieldError('title', 'Без назви Гусь не знайде цю пригоду потім.');
            titleInput.focus();

            return false;
        }

        if (titleInput.value.length > 120) {
            setFieldError('title', 'Назва розігналася далі 120 символів. Трошки підріжте.');
            titleInput.focus();

            return false;
        }

        setFieldError('title');

        return true;
    };

    const validateDescription = () => {
        descriptionInput.value = descriptionInput.value.trim();

        if (descriptionInput.value === '') {
            setFieldError('description', 'Підкиньте Гусю хоч кілька слів про задум.');
            descriptionInput.focus();

            return false;
        }

        if (descriptionInput.value.length > 500) {
            setFieldError('description', 'Гусь просив коротко: до 500 символів, будь ласка.');
            descriptionInput.focus();

            return false;
        }

        setFieldError('description');

        return true;
    };

    const validateBudget = () => {
        if (budgetInput.validity.badInput || (budgetInput.value !== '' && ! Number.isFinite(budgetInput.valueAsNumber))) {
            setFieldError('budget_amount', 'Бюджет має бути числом. Без фінансового сюрреалізму.');
            budgetInput.focus();

            return false;
        }

        if (budgetInput.value !== '' && budgetInput.valueAsNumber < 0) {
            setFieldError('budget_amount', 'Бюджет не може бути відʼємним. Навіть Гусь так не вміє.');
            budgetInput.focus();

            return false;
        }

        if (budgetInput.value !== '' && budgetInput.valueAsNumber > 9999999999.99) {
            setFieldError('budget_amount', 'Бюджет завеликий. Тут уже потрібен не Гусь, а казначейство.');
            budgetInput.focus();

            return false;
        }

        setFieldError('budget_amount');

        return true;
    };

    const showRequestError = (message = '') => {
        requestError.textContent = message;
        requestError.classList.toggle('hidden', message === '');

        if (message === '') {
            serverError?.classList.add('hidden');
        }
    };

    const setChecking = (checking) => {
        stepPanels.forEach((panel) => {
            panel.hidden = checking || Number(panel.dataset.createStep) !== currentStep;
        });
        checkingPanel.hidden = ! checking;
        submitButton.disabled = checking;
    };

    eventCreateForm.dataset.enhanced = 'true';
    setStep(currentStep);

    eventCreateForm.querySelector('[data-create-next]')?.addEventListener('click', () => {
        if (validateTitle()) {
            showRequestError();
            setStep(2);
            descriptionInput.focus();
        }
    });

    eventCreateForm.querySelector('[data-create-back]')?.addEventListener('click', () => {
        showRequestError();
        setStep(1);
        titleInput.focus();
    });

    eventCreateForm.querySelectorAll('[data-create-example]').forEach((example) => {
        example.addEventListener('click', () => {
            descriptionInput.value = example.dataset.createExample;
            descriptionInput.dispatchEvent(new Event('input'));
            descriptionInput.focus();
        });
    });

    titleInput.addEventListener('input', () => setFieldError('title'));
    descriptionInput.addEventListener('input', () => {
        setFieldError('description');
        descriptionCount.textContent = descriptionInput.value.length;
    });
    budgetInput.addEventListener('input', () => setFieldError('budget_amount'));

    eventCreateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        showRequestError();

        if (! validateTitle()) {
            setStep(1);

            return;
        }

        if (! validateDescription()) {
            setStep(2);

            return;
        }

        if (! validateBudget()) {
            setStep(2);

            return;
        }

        setChecking(true);

        try {
            const response = await fetch(eventCreateForm.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                body: new FormData(eventCreateForm),
            });
            const payload = await response.json().catch(() => ({}));

            if (response.ok && payload.redirect) {
                window.location.assign(payload.redirect);

                return;
            }

            setChecking(false);

            if (response.status === 422 && payload.errors) {
                const titleMessage = payload.errors.title?.[0] ?? '';
                const descriptionMessage = payload.errors.description?.[0] ?? '';
                const budgetMessage = payload.errors.budget_amount?.[0] ?? '';
                setFieldError('title', titleMessage);
                setFieldError('description', descriptionMessage);
                setFieldError('budget_amount', budgetMessage);
                setStep(titleMessage ? 1 : 2);
                (titleMessage ? titleInput : descriptionMessage ? descriptionInput : budgetInput).focus();

                return;
            }

            setStep(2);
            showRequestError(payload.message || 'Гусь загубив відповідь десь між дзьобом і сервером. Спробуйте ще раз.');
        } catch {
            setChecking(false);
            setStep(2);
            showRequestError('Гусь загубив звʼязок. Нічого не зберегли — перевірте мережу й повторіть.');
        }
    });
}

document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (! window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

const correctionToggle = document.querySelector('[data-plan-correction-toggle]');
const correctionPanel = document.querySelector('[data-plan-correction-panel]');

if (correctionToggle && correctionPanel) {
    const correctionInput = correctionPanel.querySelector('[data-plan-correction-input]');

    correctionToggle.addEventListener('click', () => {
        const willOpen = correctionPanel.classList.contains('hidden');

        correctionPanel.classList.toggle('hidden', ! willOpen);
        correctionToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

        if (willOpen) {
            correctionInput?.focus();
        }
    });
}

const silpoDialog = document.querySelector('[data-silpo-dialog]');

if (silpoDialog instanceof HTMLDialogElement) {
    const loadingPanel = silpoDialog.querySelector('[data-silpo-loading]');
    const resetPanel = silpoDialog.querySelector('[data-silpo-reset]');
    const guardPanel = silpoDialog.querySelector('[data-silpo-guard]');
    const fulfilmentPanel = silpoDialog.querySelector('[data-silpo-fulfilment]');
    const fulfilmentContent = silpoDialog.querySelector('[data-silpo-fulfilment-content]');
    const fulfilmentReview = silpoDialog.querySelector('[data-silpo-route-review]');
    const routeHomeButton = silpoDialog.querySelector('[data-silpo-route-home]');
    const runPanel = silpoDialog.querySelector('[data-silpo-run]');
    const minimizedHarness = document.querySelector('[data-silpo-dialog-minimized]');
    const minimizeButton = silpoDialog.querySelector('[data-silpo-dialog-minimize]');
    const restoreButton = minimizedHarness?.querySelector('[data-silpo-dialog-restore]');
    const stagedItemsContainer = runPanel.querySelector('[data-silpo-staged-items]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let runUrl = null;
    let confirmUrl = null;
    let confirmButtonLabel = 'Підтверджую товари — додати в кошик';
    let resetUrl = null;
    let resetToken = null;
    let verifiedResetToken = null;
    let discoverUrl = null;
    let startUrl = null;
    let reviewToken = null;
    let fulfilmentInitial = null;
    let lastSequence = 0;
    let pollTimer = null;
    let pollPending = false;
    let harnessMinimized = false;
    let renderedStagedItemKeys = null;
    const pendingStagedRevealKeys = new Set();

    const money = (value) => value === null || value === undefined
        ? '—'
        : `${new Intl.NumberFormat('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))} ₴`;

    const quantity = (value) => new Intl.NumberFormat('uk-UA', { maximumFractionDigits: 3 }).format(Number(value ?? 0));

    const scrollBehavior = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';

    const updateMinimizedHarness = ({ title, message, progress = null, active = false }) => {
        if (! minimizedHarness) {
            return;
        }

        minimizedHarness.querySelector('[data-silpo-minimized-title]').textContent = title;
        minimizedHarness.querySelector('[data-silpo-minimized-status]').textContent = message;
        minimizedHarness.querySelector('img')?.classList.toggle('goose-working', active);
        const progressWrap = minimizedHarness.querySelector('[data-silpo-minimized-progress-wrap]');
        const hasProgress = progress !== null;
        progressWrap.classList.toggle('hidden', ! hasProgress);

        if (hasProgress) {
            minimizedHarness.querySelector('[data-silpo-minimized-progress]').style.width = `${progress}%`;
            minimizedHarness.querySelector('[data-silpo-minimized-progress-label]').textContent = `${progress}%`;
        }
    };

    const showPanel = (target) => {
        [loadingPanel, resetPanel, guardPanel, fulfilmentPanel, runPanel].forEach((panel) => {
            panel?.classList.toggle('hidden', panel !== target);
            panel?.classList.toggle('grid', panel === target && [loadingPanel, resetPanel, guardPanel].includes(panel));
        });

        if (target === loadingPanel) {
            updateMinimizedHarness({
                title: 'Гусь працює',
                message: loadingPanel.querySelector('[data-silpo-loading-message]').textContent,
                active: true,
            });
        } else if (target === resetPanel) {
            updateMinimizedHarness({
                title: 'Гусь чекає на дозвіл',
                message: 'Копія й повне очищення почнуться лише після вашого підтвердження.',
            });
        } else if (target === fulfilmentPanel) {
            updateMinimizedHarness({
                title: 'Гусь чекає на маршрут',
                message: 'Розгорніть вікно й підкажіть, куди йому летіти.',
            });
        }
    };

    const showLoading = (message) => {
        loadingPanel.querySelector('[data-silpo-loading-message]').textContent = message;
        showPanel(loadingPanel);
    };

    const fetchJson = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(options.method && options.method !== 'GET' ? {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                } : {}),
                ...(options.headers ?? {}),
            },
            ...options,
        });
        const payload = await response.json().catch(() => ({}));

        if (! response.ok) {
            const error = new Error(payload.message || 'Гусь загубив звʼязок із Сільпо. Спробуйте ще раз.');
            error.payload = payload;
            error.status = response.status;
            throw error;
        }

        return payload;
    };

    const showGuard = (payload) => {
        showPanel(guardPanel);
        const title = payload.code === 'connection_missing'
            ? 'Гусь загубив ключ від Сільпо'
            : 'Гусь уперся в зачинені двері';
        guardPanel.querySelector('[data-silpo-guard-title]').textContent = title;
        guardPanel.querySelector('[data-silpo-guard-message]').textContent = payload.message;
        updateMinimizedHarness({ title: 'Гусь зупинився', message: payload.message || title });
        const action = guardPanel.querySelector('[data-silpo-guard-action]');

        if (payload.action_url) {
            action.href = payload.action_url;
            action.textContent = payload.action_label || 'Відкрити Сільпо';
            action.classList.remove('hidden');
        } else {
            action.classList.add('hidden');
            action.removeAttribute('href');
        }
    };

    const element = (tag, className, text = '') => {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;

        return node;
    };

    const actionButton = (label, onClick, secondary = false) => {
        const button = element(
            'button',
            secondary
                ? 'rounded-2xl border-2 border-ink bg-paper px-4 py-3 text-left font-extrabold transition hover:bg-yellow/35 disabled:cursor-not-allowed disabled:opacity-45'
                : 'rounded-2xl bg-green px-4 py-3 text-left font-extrabold text-white shadow-[3px_3px_0_#20201D] transition hover:-translate-y-0.5 hover:bg-green-dark disabled:cursor-not-allowed disabled:opacity-45',
            label,
        );
        button.type = 'button';
        button.addEventListener('click', async (event) => {
            try {
                await onClick(event);
            } catch (error) {
                showGuard(error.payload ?? { message: error.message });
            }
        });

        return button;
    };

    const detail = (label, value) => {
        const wrapper = element('div', 'rounded-2xl bg-paper p-3');
        wrapper.append(
            element('dt', 'text-xs font-bold text-muted', label),
            element('dd', 'mt-1 font-extrabold', value || '—'),
        );

        return wrapper;
    };

    const cartWarnings = (validations) => {
        const messages = (validations ?? [])
            .map((validation) => validation.message)
            .filter((message) => typeof message === 'string' && message.trim() !== '');

        if (messages.length === 0) {
            return null;
        }

        const warning = element('div', 'mt-3 rounded-2xl border-2 border-orange/30 bg-orange/8 p-3');
        warning.append(element('p', 'text-sm font-extrabold text-orange-dark', 'Сільпо просить звернути увагу'));
        const list = element('ul', 'mt-2 space-y-1 text-sm leading-6 text-muted');
        messages.forEach((message) => list.append(element('li', '', `→ ${message}`)));
        warning.append(list);

        return warning;
    };

    const setFulfilmentBody = (title, eyebrow, description = '') => {
        showPanel(fulfilmentPanel);
        fulfilmentReview.classList.add('hidden');
        reviewToken = null;
        routeHomeButton.classList.toggle('hidden', fulfilmentInitial === null || title === 'Скажіть Гусю, куди й як доставити');
        fulfilmentContent.replaceChildren();
        const intro = element('div', 'mb-4');

        if (eyebrow) {
            intro.append(element('p', 'text-xs font-extrabold uppercase tracking-[0.14em] text-green-dark', eyebrow));
        }

        intro.append(element('h5', `${eyebrow ? 'mt-1 ' : ''}font-display text-3xl leading-[1.1]`, title));

        if (description) {
            intro.append(element('p', 'mt-2 max-w-3xl text-sm leading-6 text-muted', description));
        }

        fulfilmentContent.append(intro);
    };

    const discover = async (input) => {
        if (! discoverUrl) {
            throw new Error('Гусь загубив двері до вибору маршруту.');
        }

        if (! verifiedResetToken) {
            throw new Error('Спершу підтвердьте збереження копії й очищення кошика.');
        }

        showLoading('Гусь перевіряє чистий кошик і свіжий маршрут…');

        return fetchJson(discoverUrl, {
            method: 'POST',
            body: JSON.stringify({ ...input, reset_token: verifiedResetToken }),
        });
    };

    const renderReview = (review, token) => {
        showPanel(fulfilmentPanel);
        fulfilmentContent.replaceChildren();
        routeHomeButton.classList.remove('hidden');
        reviewToken = token;
        const summary = fulfilmentReview.querySelector('[data-silpo-review-summary]');
        fulfilmentReview.querySelector('[data-silpo-review-split]')?.remove();
        fulfilmentReview.querySelector('[data-silpo-review-validations]')?.remove();
        summary.replaceChildren(
            detail('Отримання', review.delivery_label),
            detail('Куди', review.address_label),
            detail('Звідки збирає Сільпо', (review.branch_labels ?? []).join(' + ')),
            detail('Час', review.timeslot),
            detail('У кошику вже є', `${review.items_count ?? 0} позицій`),
            detail('Поточна сума', money(review.total)),
        );
        const warnings = cartWarnings(review.validations);

        if (warnings) {
            warnings.dataset.silpoReviewValidations = '';
            summary.after(warnings);
        }

        if ((review.shipments_count ?? 0) > 1) {
            const split = element('p', 'mt-3 rounded-2xl bg-yellow/40 p-3 text-sm font-bold');
            split.dataset.silpoReviewSplit = '';
            split.textContent = `Сільпо розділить маршрут на ${review.shipments_count} відправлення. Гусь звірить кожне.`;
            (warnings ?? summary).after(split);
        }

        fulfilmentReview.classList.remove('hidden');
        fulfilmentReview.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    const renderSlots = async (routeToken) => {
        const payload = await discover({ stage: 'slots', token: routeToken });
        setFulfilmentBody('Коли Гусю вирушати?', 'Доступний час', 'Показуємо тільки ті вікна, які Сільпо щойно підтвердило для цього магазину й способу отримання.');
        const list = element('div', 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3');

        if (payload.preference_note) {
            fulfilmentContent.append(element('p', 'mb-4 rounded-2xl bg-yellow/45 p-3 text-sm font-bold', payload.preference_note));
        }

        if ((payload.slots ?? []).length === 0) {
            list.append(element('p', 'rounded-2xl border-2 border-orange/30 bg-orange/8 p-4 text-sm font-bold', 'Вільний час розлетівся. Спробуйте інший маршрут.'));
        }

        (payload.slots ?? []).forEach((slot) => {
            const card = element(
                'button',
                `rounded-[20px] border-2 p-4 text-left transition hover:-translate-y-0.5 hover:border-green hover:bg-green-soft/20 ${slot.recommended ? 'border-green bg-green-soft/35 shadow-[3px_3px_0_#F7C84B]' : 'border-ink/15 bg-paper'}`,
            );
            card.type = 'button';

            if (slot.recommended) {
                card.append(element('span', 'mb-2 inline-flex rounded-full bg-green px-2.5 py-1 text-xs font-extrabold text-white', 'Найближче до побажання'));
            }

            card.append(
                element('p', 'font-display text-2xl', slot.label),
                element('p', 'mt-2 text-xs font-bold text-muted', `Доставка: ${money(slot.delivery_cost)} · мінімум: ${money(slot.min_order_cost)}`),
            );
            card.addEventListener('click', async () => {
                try {
                    const reviewed = await discover({
                        stage: 'review',
                        token: payload.route_token,
                        slot_start: slot.start,
                        slot_end: slot.end,
                    });
                    renderReview(reviewed.review, reviewed.review_token);
                } catch (error) {
                    showGuard(error.payload ?? { message: error.message });
                }
            });
            list.append(card);
        });
        fulfilmentContent.append(list);
    };

    const renderRouteOptions = (options) => {
        setFulfilmentBody('Оберіть, як Гусь дістанеться кошика', 'Маршрути від Сільпо', 'Адреса отримання й магазин, який збирає кошик, — різні речі. Гусь покаже обидві, щоб ніякої телепортації будинків.');
        const list = element('div', 'grid gap-3 lg:grid-cols-2');

        (options ?? []).forEach((option) => {
            if (option.kind === 'nova_poshta') {
                const card = element('div', `rounded-[20px] border-2 p-4 ${option.preferred ? 'border-green bg-green-soft/25' : 'border-ink/15 bg-paper'}`);

                if (option.preferred) {
                    card.append(element('span', 'mb-2 inline-flex rounded-full bg-yellow px-2.5 py-1 text-xs font-extrabold', 'Гусь почув цей спосіб'));
                }

                card.append(
                    element('p', 'font-display text-2xl', option.delivery_label),
                    element('p', 'mt-1 text-sm leading-6 text-muted', option.description),
                    actionButton('Гусю, знайди місто Нової пошти', () => renderNovaSearch(option.context_token)),
                );
                card.lastElementChild.classList.add('mt-3', 'w-full');
                list.append(card);

                return;
            }

            const card = element('div', `rounded-[20px] border-2 p-4 ${option.writable ? 'border-green/35 bg-green-soft/15' : 'border-orange/30 bg-orange/8'}`);

            if (option.preferred) {
                card.classList.add('ring-3', 'ring-yellow/70');
                card.append(element('span', 'mb-2 inline-flex rounded-full bg-yellow px-2.5 py-1 text-xs font-extrabold', 'Гусь почув цей спосіб'));
            }
            const destinationLabel = option.delivery_type === 'SelfPickup'
                ? 'Де забрати'
                : 'Куди доставити';
            card.append(
                element('p', 'font-display text-2xl', option.delivery_label),
                element('p', 'mt-2 text-xs font-extrabold uppercase tracking-[0.12em] text-green-dark', destinationLabel),
                element('p', 'mt-1 font-extrabold', option.address_label),
            );

            if (option.branch_label && option.branch_label !== option.address_label) {
                card.append(
                    element('p', 'mt-3 text-xs font-extrabold uppercase tracking-[0.12em] text-muted', 'Звідки збирає Сільпо'),
                    element('p', 'mt-1 text-sm font-bold leading-6', option.branch_label),
                );
            }

            if (option.message) {
                card.append(element('p', 'mt-3 text-sm leading-6 text-muted', option.message));
            }

            if (option.route_token) {
                const choose = actionButton('Цим шляхом — показати час', () => renderSlots(option.route_token));
                choose.classList.add('mt-3', 'w-full');
                card.append(choose);
            } else {
                const fallback = actionButton('До попередніх і збережених адрес', renderManualFulfilment, false);
                fallback.classList.add('mt-3', 'w-full');
                card.append(fallback);
            }

            list.append(card);
        });
        fulfilmentContent.append(list);
    };

    const renderAddressOptions = async (token) => {
        const payload = await discover({ stage: 'address_options', token });
        renderRouteOptions(payload.options);
    };

    const renderAddressCandidates = (payload) => {
        setFulfilmentBody('Звірте точну адресу', 'Гусь не вгадує координати', 'Оберіть саме той варіант, який підтвердило Сільпо. Від цієї точки залежать магазин і доступні способи отримання.');
        const comparison = element('div', 'grid gap-3 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]');
        const heard = element('section', 'rounded-[20px] border-2 border-yellow bg-yellow/30 p-4');
        heard.append(
            element('p', 'text-xs font-extrabold uppercase tracking-[0.13em] text-orange-dark', 'Гусь почув…'),
            element('p', 'mt-2 font-extrabold leading-6', payload.heard || payload.address_query || 'Нову адресу'),
        );
        const found = element('section', 'rounded-[20px] border-2 border-green/35 bg-green-soft/15 p-4');
        found.append(element('p', 'text-xs font-extrabold uppercase tracking-[0.13em] text-green-dark', 'Сільпо знайшло…'));
        const list = element('div', 'mt-3 grid gap-2');

        if ((payload.addresses ?? []).length === 0) {
            list.append(element('p', 'rounded-2xl border-2 border-orange/30 bg-orange/8 p-4 font-bold', 'Точка не знайшлася. Спробуйте повнішу адресу або відкрийте ручний вибір — телепатію в цей реліз знову не завезли.'));
        }

        (payload.addresses ?? []).forEach((address) => {
            list.append(actionButton(address.label, () => renderAddressOptions(address.token), true));
        });
        found.append(list);
        comparison.append(heard, found);
        fulfilmentContent.append(comparison);
    };

    const renderAddressSearch = () => {
        setFulfilmentBody('Куди саме?', 'Нова точка на карті', 'Напишіть місто, вулицю й будинок. Гусь покаже доставку, магазин-збирач і найближчий самовивіз окремо. Якщо Сільпо не дасть усіх даних для домашнього запису, будинок нікуди не переїде — просто оберемо інший шлях.');
        const form = element('form', 'flex flex-col gap-3 rounded-[22px] bg-canvas p-4 sm:flex-row');
        const input = element('input', 'min-w-0 flex-1 rounded-2xl border-2 border-ink/15 bg-paper px-4 py-3 outline-none focus:border-green focus:ring-3 focus:ring-green/15');
        input.type = 'search';
        input.maxLength = 120;
        input.autocomplete = 'street-address';
        input.enterKeyHint = 'search';
        input.placeholder = 'Наприклад: Київ, Хрещатик, 1';
        input.setAttribute('aria-label', 'Адреса для пошуку');
        const submit = actionButton('Гусь, шукай адресу', () => {});
        submit.type = 'submit';
        form.append(input, submit);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const query = input.value.trim();

            if (query.length < 2) {
                input.focus();

                return;
            }

            submit.disabled = true;
            submit.textContent = 'Гусь водить дзьобом по мапі…';

            try {
                const payload = await discover({ stage: 'address_search', query });
                renderAddressCandidates(payload);
            } catch (error) {
                showGuard(error.payload ?? { message: error.message });
            } finally {
                submit.disabled = false;
                submit.textContent = 'Гусь, шукай адресу';
            }
        });
        fulfilmentContent.append(form);
        input.focus();
    };

    const renderNovaOffices = async (settlementToken) => {
        const payload = await discover({ stage: 'nova_offices', token: settlementToken });
        setFulfilmentBody('Яке відділення впізнає Гуся?', 'Нова пошта', 'Оберіть відділення або поштомат. Далі Сільпо покаже магазин, який може туди відправити кошик.');
        const list = element('div', 'grid gap-3 sm:grid-cols-2');

        (payload.offices ?? []).forEach((office) => {
            list.append(actionButton(office.label, async () => {
                const branchPayload = await discover({ stage: 'nova_branches', token: office.token });
                renderRouteOptions(branchPayload.options);
            }, true));
        });
        fulfilmentContent.append(list);
    };

    const renderNovaSettlements = (settlements, heard = null, officeHint = null) => {
        setFulfilmentBody('Куди летить посилка?', 'Місто Нової пошти', 'Оберіть населений пункт без ворожіння на однакових назвах.');
        const list = element('div', 'grid gap-3 sm:grid-cols-2');

        if (heard) {
            const understood = element('div', 'mb-4 grid gap-3 sm:grid-cols-2');
            understood.append(
                element('p', 'rounded-2xl bg-yellow/35 p-4 text-sm font-bold', `Гусь почув… ${heard}`),
                element('p', 'rounded-2xl bg-green-soft/25 p-4 text-sm font-bold', officeHint
                    ? `Сільпо шукає місто й відділення: ${officeHint}`
                    : 'Сільпо знайшло такі населені пункти.'),
            );
            fulfilmentContent.append(understood);
        }

        if ((settlements ?? []).length === 0) {
            list.append(element('p', 'rounded-2xl border-2 border-orange/30 bg-orange/8 p-4 font-bold', 'Місто від Гуся сховалося. Спробуйте повнішу назву.'));
        }

        (settlements ?? []).forEach((settlement) => {
            list.append(actionButton(settlement.label, () => renderNovaOffices(settlement.token), true));
        });
        fulfilmentContent.append(list);
    };

    const renderNovaSearch = (contextToken) => {
        setFulfilmentBody('До якого міста летимо?', 'Нова пошта', 'Почніть вводити населений пункт, а Гусь дістане офіційний список.');
        const form = element('form', 'flex flex-col gap-3 rounded-[22px] bg-canvas p-4 sm:flex-row');
        const input = element('input', 'min-w-0 flex-1 rounded-2xl border-2 border-ink/15 bg-paper px-4 py-3 outline-none focus:border-green focus:ring-3 focus:ring-green/15');
        input.type = 'search';
        input.maxLength = 120;
        input.autocomplete = 'address-level2';
        input.enterKeyHint = 'search';
        input.placeholder = 'Наприклад: Київ';
        input.setAttribute('aria-label', 'Місто Нової пошти');
        const submit = actionButton('Гусь, знайди місто', () => {});
        submit.type = 'submit';
        form.append(input, submit);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const query = input.value.trim();

            if (query.length < 2) {
                input.focus();

                return;
            }

            submit.disabled = true;

            try {
                const payload = await discover({ stage: 'nova_settlements', token: contextToken, query });
                renderNovaSettlements(payload.settlements);
            } catch (error) {
                showGuard(error.payload ?? { message: error.message });
            } finally {
                submit.disabled = false;
            }
        });
        fulfilmentContent.append(form);
        input.focus();
    };

    const freshStartCard = () => {
        const current = element('section', 'rounded-[22px] border-2 border-green/35 bg-green-soft/15 p-4 sm:p-5');
        current.append(
            element('h6', 'font-display text-2xl', 'Кошик очищено й перевірено'),
            element('p', 'mt-2 text-sm leading-6 text-muted', 'Зашифрована копія попереднього складу лишається з цією подією. Тепер потрібен повністю свіжий вибір місця, магазину й часу.'),
        );

        if ((fulfilmentInitial?.backup?.items_count ?? 0) > 0) {
            current.append(element('p', 'mt-3 rounded-2xl bg-paper p-3 text-sm font-bold', `У копії: ${fulfilmentInitial.backup.items_count} позицій · ${money(fulfilmentInitial.backup.total)}`));
        }

        return current;
    };

    const renderManualFulfilment = () => {
        const payload = fulfilmentInitial;
        setFulfilmentBody('Оберіть маршрут вручну', 'Запасний план Гуся', 'Оберіть попередню чи збережену адресу або знайдіть іншу точку. Магазин і час у будь-якому разі вибираємо заново.');
        const layout = element('div', 'grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]');
        const change = element('section', 'rounded-[22px] bg-canvas p-4 sm:p-5');
        change.append(
            element('h6', 'font-display text-2xl', 'Інший маршрут'),
            element('p', 'mt-1 text-sm leading-6 text-muted', 'Оберіть знайому адресу або дайте Гусю нову точку для пошуку.'),
        );
        const addresses = element('div', 'mt-3 grid gap-2');
        (payload.addresses ?? []).forEach((address) => {
            addresses.append(actionButton(`${address.eyebrow}: ${address.label}`, () => renderAddressOptions(address.token), true));
        });
        change.append(addresses);
        const search = actionButton('Інша точка — нехай Гусь пошукає', renderAddressSearch, false);
        search.classList.add('mt-3', 'w-full');
        change.append(search);
        layout.append(freshStartCard(), change);
        fulfilmentContent.append(layout);
    };

    const handleRouteIntent = (payload, sentence) => {
        if (payload.kind === 'clarification') {
            renderIntentPrompt(payload.question, sentence);

            return;
        }

        if (payload.kind === 'address_candidates') {
            renderAddressCandidates(payload);

            return;
        }

        if (payload.kind === 'nova_settlements') {
            renderNovaSettlements(payload.settlements, payload.heard, payload.office_hint);

            return;
        }

        renderIntentPrompt('Гусь не впізнав маршрут. Скажіть адресу й спосіб отримання ще раз.', sentence);
    };

    const renderIntentPrompt = (question = null, previousSentence = '') => {
        setFulfilmentBody('Скажіть Гусю, куди й як доставити', '', 'Оберіть місце, магазин і час заново. Спершу Гусь розбере фразу, потім Сільпо окремо підтвердить кожну частину маршруту.');
        const layout = element('div', 'grid gap-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(18rem,0.9fr)]');
        const prompt = element('section', 'rounded-[24px] border-2 border-ink bg-yellow/25 p-4 shadow-[4px_4px_0_#20201D] sm:p-5');

        if (question) {
            prompt.append(element('p', 'mb-4 rounded-2xl bg-paper p-3 font-extrabold text-orange-dark', question));
        }

        const form = element('form', 'grid gap-3');
        const input = element('textarea', 'min-h-28 w-full resize-y rounded-2xl border-2 border-ink/20 bg-paper px-4 py-3 leading-6 outline-none focus:border-green focus:ring-3 focus:ring-green/15');
        input.maxLength = 600;
        input.rows = 3;
        input.autocomplete = 'street-address';
        input.enterKeyHint = 'done';
        input.value = previousSentence;
        input.placeholder = 'Доставка додому: Київ, вул. Саксаганського, 57-Б. Завтра після 18:00';
        input.setAttribute('aria-label', 'Куди й як доставити кошик');
        const example = element('p', 'text-sm leading-6 text-muted', 'Наприклад: Доставка додому: Київ, вул. Саксаганського, 57-Б. Завтра після 18:00');
        const submit = actionButton('Гусю, розбери маршрут', () => {});
        submit.type = 'submit';
        submit.classList.remove('text-left');
        submit.classList.add('w-full', 'text-center', 'sm:w-auto');
        form.append(input, example, submit);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const sentence = input.value.trim();

            if (sentence.length < 2) {
                input.focus();

                return;
            }

            submit.disabled = true;
            submit.textContent = 'Гусь розкладає маршрут по пірʼїнках…';

            try {
                handleRouteIntent(await discover({ stage: 'intent', query: sentence }), sentence);
            } catch (error) {
                showGuard(error.payload ?? { message: error.message });
            } finally {
                submit.disabled = false;
                submit.textContent = 'Гусю, розбери маршрут';
            }
        });
        prompt.append(form);
        const manual = actionButton('Обрати маршрут вручну', renderManualFulfilment, true);
        manual.classList.add('mt-4', 'w-full', 'sm:w-auto');
        prompt.append(manual);
        layout.append(prompt, freshStartCard());
        fulfilmentContent.append(layout);

        if (question) {
            input.focus();
        }
    };

    const renderInitialFulfilment = (payload) => {
        fulfilmentInitial = payload;
        verifiedResetToken = payload.reset_token;
        discoverUrl = payload.discover_url;
        startUrl = payload.start_url;
        renderIntentPrompt();
    };

    const productCard = (product, compact = false, stagedItemKey = null) => {
        const card = document.createElement('article');
        card.className = compact
            ? 'flex items-center justify-between gap-3 rounded-xl bg-canvas px-3 py-2 text-sm'
            : 'grid grid-cols-[3.5rem_minmax(0,1fr)_auto] items-center gap-3 rounded-2xl border border-ink/10 bg-paper p-3';

        if (stagedItemKey !== null) {
            card.dataset.silpoStagedItemKey = stagedItemKey;
        }

        if (! compact) {
            const visual = document.createElement('div');
            visual.className = 'grid size-14 place-items-center overflow-hidden rounded-xl bg-white text-xl';
            const imageUrl = typeof product.image === 'string' ? product.image : '';

            if (/^https:\/\//i.test(imageUrl)) {
                const image = document.createElement('img');
                image.className = 'size-full object-contain';
                image.src = imageUrl;
                image.alt = '';
                image.loading = 'lazy';
                visual.append(image);
            } else {
                visual.textContent = '🧺';
            }

            card.append(visual);
        }

        const copy = document.createElement('div');
        copy.className = 'min-w-0';
        const name = document.createElement('p');
        name.className = compact ? 'truncate font-bold' : 'line-clamp-2 text-sm font-extrabold';
        name.textContent = product.name || 'Товар Сільпо';
        const meta = document.createElement('p');
        meta.className = 'mt-0.5 text-xs text-muted';
        meta.textContent = `${quantity(product.quantity)} × ${money(product.price)}`;
        copy.append(name, meta);

        if (! compact && product.need_name) {
            const need = document.createElement('p');
            need.className = 'mt-1 text-xs font-bold text-green-dark';
            need.textContent = `Для: ${product.need_name}`;
            copy.append(need);
        }

        if (! compact && product.review_note) {
            const review = document.createElement('p');
            review.className = 'mt-2 text-xs leading-5 text-orange-dark';
            review.textContent = product.review_note;
            copy.append(review);
        }

        if (! compact && product.selection_explanation
            && (product.match_evidence === 'same_role' || product.safety_evidence === 'unverified')) {
            const explanation = document.createElement('p');
            explanation.className = 'mt-1 text-xs leading-5 text-muted';
            explanation.textContent = product.selection_explanation;
            copy.append(explanation);
        }

        const total = document.createElement('p');
        total.className = 'shrink-0 text-sm font-extrabold';
        total.textContent = money(product.estimated_total ?? product.total ?? (Number(product.quantity ?? 0) * Number(product.price ?? 0)));
        card.append(copy, total);

        return card;
    };

    const renderProducts = (selector, products, compact = false) => {
        const container = runPanel.querySelector(selector);
        container.replaceChildren();

        if (products.length === 0 && ! compact) {
            const empty = document.createElement('p');
            empty.className = 'rounded-2xl border border-dashed border-ink/20 bg-paper p-4 text-sm text-muted';
            empty.textContent = 'Поки порожньо. Гусь лише зайшов.';
            container.append(empty);

            return;
        }

        products.forEach((product) => container.append(productCard(product, compact)));
    };

    const stagedItemKey = (product, index) => [
        product.need_key || `position-${index}`,
        product.product_id || product.external_product_id || product.name || `product-${index}`,
    ].join('::');

    const stagedCardsForKeys = (keys) => Array.from(stagedItemsContainer.children)
        .filter((card) => keys.includes(card.dataset.silpoStagedItemKey));

    const revealStagedItems = (keys) => {
        const cards = stagedCardsForKeys(keys);

        if (cards.length === 0) {
            return;
        }

        cards.forEach((card) => card.classList.add('staged-cart-item-arrival'));
        window.requestAnimationFrame(() => {
            stagedItemsContainer.scrollTo({
                top: stagedItemsContainer.scrollHeight,
                behavior: scrollBehavior(),
            });
        });
    };

    const revealPendingStagedItems = () => {
        const currentKeys = new Set(renderedStagedItemKeys ?? []);
        const keys = Array.from(pendingStagedRevealKeys).filter((key) => currentKeys.has(key));
        pendingStagedRevealKeys.clear();
        revealStagedItems(keys);
    };

    const renderStagedProducts = (products) => {
        const previousScrollTop = stagedItemsContainer.scrollTop;
        const currentKeys = products.map(stagedItemKey);
        const currentKeySet = new Set(currentKeys);
        const addedKeys = renderedStagedItemKeys === null
            ? []
            : currentKeys.filter((key) => ! renderedStagedItemKeys.has(key));

        stagedItemsContainer.replaceChildren();

        if (products.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'rounded-2xl border border-dashed border-ink/20 bg-paper p-4 text-sm text-muted';
            empty.textContent = 'Поки порожньо. Гусь лише зайшов.';
            stagedItemsContainer.append(empty);
        } else {
            products.forEach((product, index) => {
                stagedItemsContainer.append(productCard(product, false, currentKeys[index]));
            });
        }

        renderedStagedItemKeys = currentKeySet;
        Array.from(pendingStagedRevealKeys).forEach((key) => {
            if (! currentKeySet.has(key)) {
                pendingStagedRevealKeys.delete(key);
            }
        });

        if (addedKeys.length === 0) {
            stagedItemsContainer.scrollTop = previousScrollTop;

            return;
        }

        if (silpoDialog.open) {
            revealStagedItems(addedKeys);

            return;
        }

        addedKeys.forEach((key) => pendingStagedRevealKeys.add(key));
        stagedItemsContainer.scrollTop = previousScrollTop;
    };

    const renderRun = (payload) => {
        showPanel(runPanel);
        runPanel.querySelector('[data-silpo-status-label]').textContent = payload.status_label;
        runPanel.querySelector('[data-silpo-mode-label]').textContent = payload.mode_label;
        runPanel.querySelector('[data-silpo-progress]').style.width = `${payload.progress}%`;
        runPanel.querySelector('[data-silpo-progress-label]').textContent = `${payload.progress}%`;
        runPanel.querySelector('[data-silpo-live-dot]').classList.toggle('hidden', payload.terminal);
        const waitingForUser = ['waiting_for_answer', 'waiting_for_confirmation'].includes(payload.status);
        updateMinimizedHarness({
            title: payload.terminal ? 'Гусь повернувся' : (waitingForUser ? 'Гусь чекає на вас' : 'Гусь працює'),
            message: payload.status_label,
            progress: payload.progress,
            active: ! payload.terminal && ! waitingForUser,
        });

        const steps = runPanel.querySelector('[data-silpo-steps]');
        payload.steps.forEach((step) => {
            const row = document.createElement('li');
            row.className = 'border-l-2 border-yellow/55 pl-3 text-paper/90';
            row.dataset.sequence = step.sequence;
            row.textContent = step.message;
            steps.append(row);
        });

        if (payload.steps.length > 0) {
            steps.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: scrollBehavior() });
        }

        lastSequence = Math.max(lastSequence, Number(payload.last_sequence ?? 0));
        renderStagedProducts(payload.staged_items);
        renderProducts('[data-silpo-existing-items]', payload.existing_items, true);
        runPanel.querySelector('[data-silpo-existing-badge]').textContent = `(${payload.existing_items.length})`;
        runPanel.querySelector('[data-silpo-staged-total]').textContent = money(payload.estimated_total ?? 0);

        const blocker = runPanel.querySelector('[data-silpo-blocker]');
        blocker.classList.toggle('hidden', payload.status !== 'waiting_for_answer');
        blocker.querySelector('[data-silpo-blocker-message]').textContent = payload.blocker || '';
        const confirmation = runPanel.querySelector('[data-silpo-confirmation]');
        confirmation.classList.toggle('hidden', ! payload.requires_confirmation);
        confirmUrl = payload.requires_confirmation ? payload.confirm_url : null;
        confirmButtonLabel = payload.confirmation_label ?? 'Підтверджую товари — додати в кошик';
        confirmation.querySelector('[data-silpo-confirm]').textContent = confirmButtonLabel;
        confirmation.querySelector('[data-silpo-confirm-title]').textContent = payload.commit_retry_available
            ? 'Набір збережено — можна повторити'
            : 'Останній людський погляд';
        confirmation.querySelector('[data-silpo-confirm-copy]').textContent = payload.commit_retry_available
            ? 'Гусь залишив рівно той набір, який ви вже підтвердили. Перед повтором він перевірить маршрут і не допустить сторонні товари.'
            : 'Перевірте реальні товари Сільпо, рольові заміни, знаки питання щодо паковання, кількості й суму. До цього підтвердження Гусь нічого в кошик не записує.';

        const warnings = [...(payload.warnings ?? [])];

        if (payload.error) {
            warnings.push(payload.error);
        }

        const warningPanel = runPanel.querySelector('[data-silpo-warnings]');
        const warningList = runPanel.querySelector('[data-silpo-warning-list]');
        warningPanel.classList.toggle('hidden', warnings.length === 0);
        warningList.replaceChildren();
        warnings.forEach((warning) => {
            const item = document.createElement('li');
            item.textContent = `→ ${warning}`;
            warningList.append(item);
        });
    };

    const stopPolling = () => {
        window.clearTimeout(pollTimer);
        pollTimer = null;
    };

    const pollRun = async () => {
        if (! runUrl || pollPending || (! silpoDialog.open && ! harnessMinimized)) {
            return;
        }

        if (document.hidden) {
            pollTimer = window.setTimeout(pollRun, 1800);

            return;
        }

        pollPending = true;

        try {
            const separator = runUrl.includes('?') ? '&' : '?';
            const payload = await fetchJson(`${runUrl}${separator}after=${lastSequence}`);
            renderRun(payload);

            if (! payload.terminal && ! ['waiting_for_answer', 'waiting_for_confirmation'].includes(payload.status)) {
                pollTimer = window.setTimeout(pollRun, 1800);
            }
        } catch (error) {
            showGuard({ message: error.message });
        } finally {
            pollPending = false;
        }
    };

    const openRun = (url, preserveStagedBaseline = false) => {
        if (! preserveStagedBaseline) {
            renderedStagedItemKeys = null;
            pendingStagedRevealKeys.clear();
        }

        runUrl = url;
        lastSequence = 0;
        runPanel.querySelector('[data-silpo-steps]').replaceChildren();
        stopPolling();
        pollRun();
    };

    const preflight = async () => {
        showLoading('Гусь готує безпечний старт…');
        stopPolling();
        resetUrl = null;
        resetToken = null;
        verifiedResetToken = null;

        try {
            const payload = await fetchJson(silpoDialog.dataset.preflightUrl);

            if (payload.active_run_url) {
                openRun(payload.active_run_url);

                return;
            }

            resetUrl = payload.reset_url;
            resetToken = payload.reset_token;
            showPanel(resetPanel);
        } catch (error) {
            showGuard(error.payload ?? { message: error.message });
        }
    };

    const minimizeHarness = () => {
        if (! silpoDialog.open || ! minimizedHarness) {
            return;
        }

        harnessMinimized = true;
        silpoDialog.close('minimized');
        minimizedHarness.classList.remove('hidden');
        window.requestAnimationFrame(() => restoreButton?.focus());
    };

    const restoreHarness = () => {
        if (! harnessMinimized || silpoDialog.open) {
            return;
        }

        harnessMinimized = false;
        minimizedHarness?.classList.add('hidden');
        silpoDialog.showModal();
        window.requestAnimationFrame(() => {
            revealPendingStagedItems();
            minimizeButton?.focus();
        });
    };

    document.querySelectorAll('[data-silpo-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (harnessMinimized) {
                restoreHarness();

                return;
            }

            silpoDialog.showModal();
            preflight();
        });
    });

    resetPanel.querySelector('[data-silpo-reset-confirm]').addEventListener('click', async (event) => {
        const button = event.currentTarget;

        if (! resetUrl || ! resetToken) {
            showGuard({ message: 'Підтвердження очищення вже неактуальне. Відкрийте похід Гуся ще раз.' });

            return;
        }

        button.disabled = true;
        showLoading('Гусь зберігає копію й звільняє кошик…');

        try {
            const payload = await fetchJson(resetUrl, {
                method: 'POST',
                body: JSON.stringify({ reset_token: resetToken }),
            });
            renderInitialFulfilment(payload);
        } catch (error) {
            showGuard(error.payload ?? { message: error.message });
        } finally {
            button.disabled = false;
        }
    });

    silpoDialog.querySelector('[data-silpo-start]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const mode = silpoDialog.querySelector('input[name="silpo-mode"]:checked')?.value ?? 'assisted';

        if (! reviewToken || ! startUrl) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Гусь звіряє маршрут і натягує авоську…';

        try {
            const payload = await fetchJson(startUrl, {
                method: 'POST',
                body: JSON.stringify({ mode, review_token: reviewToken }),
            });
            openRun(payload.run_url);
        } catch (error) {
            showGuard(error.payload ?? { message: error.message });
        } finally {
            button.disabled = false;
            button.textContent = 'Гусю, маршрут є — лети збирати кошик';
        }
    });

    silpoDialog.querySelector('[data-silpo-continue]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const input = silpoDialog.querySelector('[data-silpo-answer]');
        const answer = input.value.trim();

        if (! answer || ! runUrl) {
            input.focus();

            return;
        }

        button.disabled = true;

        try {
            const current = await fetchJson(`${runUrl}?after=${lastSequence}`);
            await fetchJson(current.continue_url, {
                method: 'POST',
                body: JSON.stringify({ answer }),
            });
            input.value = '';
            openRun(runUrl, true);
        } catch (error) {
            showGuard(error.payload ?? { message: error.message });
        } finally {
            button.disabled = false;
        }
    });

    silpoDialog.querySelector('[data-silpo-confirm]').addEventListener('click', async (event) => {
        const button = event.currentTarget;

        if (! confirmUrl || ! runUrl) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Гусь ще раз звіряє кошик…';

        try {
            await fetchJson(confirmUrl, { method: 'POST' });
            openRun(runUrl, true);
        } catch (error) {
            showGuard(error.payload ?? { message: error.message });
        } finally {
            button.disabled = false;
            button.textContent = confirmButtonLabel;
        }
    });

    routeHomeButton.addEventListener('click', () => renderInitialFulfilment(fulfilmentInitial));
    silpoDialog.querySelector('[data-silpo-recheck]').addEventListener('click', preflight);
    minimizeButton?.addEventListener('click', minimizeHarness);
    restoreButton?.addEventListener('click', restoreHarness);
    silpoDialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        minimizeHarness();
    });
    silpoDialog.addEventListener('click', (event) => {
        if (event.target === silpoDialog) {
            minimizeHarness();
        }
    });
}

document.querySelectorAll('[data-question-custom-input]').forEach((input) => {
    input.addEventListener('input', () => {
        const choice = input.closest('label')?.querySelector('[data-question-custom-choice]');

        if (choice && input.value.trim() !== '') {
            choice.checked = true;
        }
    });
});

const workspace = document.querySelector('[data-event-workspace]');

if (workspace) {
    const overlay = document.querySelector('[data-analysis-overlay]');
    const minimizedOverlay = document.querySelector('[data-analysis-minimized]');
    const analysisForms = Array.from(document.querySelectorAll('[data-analysis-form]'));
    const analysisButtons = analysisForms
        .map((form) => form.querySelector('[data-analysis-button]'))
        .filter(Boolean);
    const minimizeButton = document.querySelector('[data-analysis-minimize]');
    const restoreButton = document.querySelector('[data-analysis-restore]');
    const analysisSteps = overlay?.querySelector('[data-analysis-steps]');
    const analysisElapsed = overlay?.querySelector('[data-analysis-elapsed]');
    const initialStateVersion = Number(workspace.dataset.eventStateVersion);
    const initialPlanStatus = workspace.dataset.eventPlanStatus;
    let analysisTaskId = null;
    let analysisMinimized = false;
    let analysisStartedAt = null;

    const activeAnalysisStages = ['waiting_for_quiet', 'waiting_for_images', 'summarizing'];
    const analysisScrollBehavior = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    const analysisStorageKey = (taskId) => `hto-sho:analysis:${taskId}:steps`;
    const analysisTitle = (stage) => ({
        waiting_for_quiet: 'Гусь слухає новий контекст',
        waiting_for_images: 'Гусь читає картинки',
        summarizing: 'Гусь складає все докупи',
        completed: 'Гусь усе розгріб',
        completed_with_warnings: 'Гусь усе розгріб, але є нюанси',
        failed: 'Гусь перечепився',
    })[stage] ?? 'Гусь працює';

    const formatAnalysisElapsed = (totalSeconds) => {
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const clock = [minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':');

        return hours > 0 ? `${String(hours).padStart(2, '0')}:${clock}` : clock;
    };

    const updateAnalysisElapsed = () => {
        if (! analysisElapsed) {
            return;
        }

        const elapsedSeconds = analysisStartedAt === null
            ? 0
            : Math.max(0, Math.floor((Date.now() - analysisStartedAt) / 1000));

        analysisElapsed.textContent = formatAnalysisElapsed(elapsedSeconds);
    };

    const storedAnalysisSteps = (taskId) => {
        if (! taskId) {
            return [];
        }

        try {
            const stored = JSON.parse(window.sessionStorage.getItem(analysisStorageKey(taskId)) ?? '[]');

            return Array.isArray(stored)
                ? stored.filter((message) => typeof message === 'string' && message.trim() !== '').slice(-100)
                : [];
        } catch {
            return [];
        }
    };

    const saveAnalysisSteps = () => {
        if (! analysisTaskId || ! analysisSteps) {
            return;
        }

        const messages = Array.from(analysisSteps.children)
            .map((row) => row.textContent)
            .filter(Boolean)
            .slice(-100);

        try {
            window.sessionStorage.setItem(analysisStorageKey(analysisTaskId), JSON.stringify(messages));
        } catch {
            // The live flow still works when browser storage is unavailable.
        }
    };

    const appendAnalysisStep = (message, { animate = true, persist = true } = {}) => {
        const normalizedMessage = typeof message === 'string' ? message.trim() : '';

        if (! analysisSteps || normalizedMessage === '' || analysisSteps.lastElementChild?.textContent === normalizedMessage) {
            return;
        }

        const row = document.createElement('li');
        row.className = 'border-l-2 border-yellow/55 pl-3 text-paper/90';

        if (animate) {
            row.classList.add('analysis-step-arrival');
        }

        row.textContent = normalizedMessage;
        analysisSteps.append(row);

        while (analysisSteps.children.length > 100) {
            analysisSteps.firstElementChild?.remove();
        }

        if (persist) {
            saveAnalysisSteps();
        }

        row.scrollIntoView({ block: 'nearest', behavior: analysisScrollBehavior() });
    };

    const useAnalysisTask = (taskId, startedAt = null) => {
        if (! taskId || ! analysisSteps) {
            return;
        }

        const parsedStartedAt = Date.parse(startedAt ?? '');

        if (taskId === analysisTaskId) {
            if (! Number.isNaN(parsedStartedAt)) {
                analysisStartedAt = parsedStartedAt;
                updateAnalysisElapsed();
            }

            return;
        }

        analysisTaskId = taskId;
        analysisStartedAt = Number.isNaN(parsedStartedAt) ? Date.now() : parsedStartedAt;
        analysisSteps.replaceChildren();
        storedAnalysisSteps(taskId).forEach((message) => {
            appendAnalysisStep(message, { animate: false, persist: false });
        });
        analysisSteps.lastElementChild?.scrollIntoView({ block: 'nearest' });
        updateAnalysisElapsed();
    };

    const setAnalysisButtons = (active) => {
        analysisButtons.forEach((button) => {
            button.dataset.idleLabel ||= button.textContent;
            button.disabled = active;
            button.textContent = active ? 'Гусь уже гребе…' : button.dataset.idleLabel;
        });
    };

    const openAnalysisDialog = () => {
        if (! (overlay instanceof HTMLDialogElement) || analysisMinimized) {
            return;
        }

        minimizedOverlay?.classList.add('hidden');

        if (! overlay.open) {
            overlay.showModal();
        }
    };

    const closeAnalysisExperience = (clearStoredSteps = false) => {
        if (overlay instanceof HTMLDialogElement && overlay.open) {
            overlay.close();
        }

        minimizedOverlay?.classList.add('hidden');
        analysisMinimized = false;

        if (clearStoredSteps && analysisTaskId) {
            try {
                window.sessionStorage.removeItem(analysisStorageKey(analysisTaskId));
            } catch {
                // Nothing needs cleanup when browser storage is unavailable.
            }
        }
    };

    const updateMinimizedAnalysis = ({ title, message, progress, active }) => {
        if (! minimizedOverlay) {
            return;
        }

        minimizedOverlay.querySelector('[data-analysis-minimized-title]').textContent = title;
        minimizedOverlay.querySelector('[data-analysis-minimized-status]').textContent = message;
        minimizedOverlay.querySelector('[data-analysis-minimized-progress]').style.width = `${progress}%`;
        minimizedOverlay.querySelector('[data-analysis-minimized-progress-label]').textContent = `${progress}%`;
        minimizedOverlay.querySelector('img')?.classList.toggle('goose-working', active);
    };

    const setOverlay = (task) => {
        if (! overlay || ! task) {
            closeAnalysisExperience();

            return;
        }

        const taskId = task.id ?? task.task_id ?? analysisTaskId;
        const active = activeAnalysisStages.includes(task.stage);
        const failed = task.stage === 'failed';
        const progress = Number(task.progress ?? 0);
        const title = analysisTitle(task.stage);
        const message = task.error || task.message || title;

        useAnalysisTask(taskId, task.started_at);
        appendAnalysisStep(task.message);

        if (task.error) {
            appendAnalysisStep(task.error);
        }

        overlay.querySelector('[data-analysis-status-title]').textContent = title;
        overlay.querySelector('[data-analysis-progress]').style.width = `${progress}%`;
        overlay.querySelector('[data-analysis-progress-label]').textContent = `${progress}%`;
        overlay.querySelector('img')?.classList.toggle('goose-working', active);
        overlay.querySelector('[data-analysis-live-dot]').classList.toggle('hidden', ! active);
        updateMinimizedAnalysis({ title, message, progress, active });
        setAnalysisButtons(active);

        if (! active && ! failed) {
            closeAnalysisExperience(true);

            return;
        }

        if (analysisMinimized) {
            minimizedOverlay?.classList.remove('hidden');
        } else {
            openAnalysisDialog();
        }
    };

    const applySourceStatus = (source) => {
        const card = document.querySelector(`[data-source-card][data-source-id="${source.id}"]`);

        if (! card) {
            return false;
        }

        const previousStatus = card.dataset.sourceStatus;
        card.dataset.sourceStatus = source.status;
        const label = card.querySelector('[data-source-status-label]');
        const message = card.querySelector('[data-source-message]');
        const progress = card.querySelector('[data-source-progress]');

        if (label) {
            label.textContent = source.status_label;
        }

        if (message) {
            message.textContent = source.message;
        }

        if (progress) {
            progress.style.width = `${source.progress}%`;
        }

        return previousStatus !== source.status && ['processed', 'failed'].includes(source.status);
    };

    const pollStatus = async () => {
        if (document.hidden) {
            return;
        }

        try {
            const response = await fetch(workspace.dataset.eventStatusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                return;
            }

            const data = await response.json();
            const sourceFinished = data.sources.some(applySourceStatus);
            setOverlay(data.full_task);

            const statusBadge = document.querySelector('[data-event-status-badge]');

            if (statusBadge) {
                statusBadge.textContent = data.status_label;
            }

            if (
                sourceFinished
                || Number(data.state_version) !== initialStateVersion
                || data.plan_generation_status !== initialPlanStatus
            ) {
                window.location.reload();
            }
        } catch {
            // A transient polling failure should not interrupt uploads or editing.
        }
    };

    analysisForms.forEach((analysisForm) => {
        analysisForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            setAnalysisButtons(true);

            try {
                const response = await fetch(analysisForm.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    body: new FormData(analysisForm),
                });

                if (! response.ok) {
                    throw new Error('Analysis request failed.');
                }

                const task = await response.json();
                analysisMinimized = false;
                setOverlay({ ...task, progress: task.stage === 'waiting_for_quiet' ? 10 : 20 });
                window.setTimeout(pollStatus, 250);
            } catch {
                setAnalysisButtons(false);
                analysisMinimized = false;
                setOverlay({
                    id: analysisTaskId ?? `request-${Date.now()}`,
                    stage: 'failed',
                    progress: 100,
                    message: 'Гусь навіть не стартував. Перевірте зʼєднання і повторіть.',
                });
            }
        });
    });

    minimizeButton?.addEventListener('click', () => {
        if (! (overlay instanceof HTMLDialogElement) || ! overlay.open) {
            return;
        }

        analysisMinimized = true;
        overlay.close();
        minimizedOverlay?.classList.remove('hidden');
    });

    restoreButton?.addEventListener('click', () => {
        analysisMinimized = false;
        minimizedOverlay?.classList.add('hidden');
        openAnalysisDialog();
        analysisSteps?.lastElementChild?.scrollIntoView({ block: 'nearest' });
    });

    if (overlay instanceof HTMLDialogElement) {
        overlay.addEventListener('cancel', (event) => {
            event.preventDefault();
            minimizeButton?.click();
        });
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) {
                minimizeButton?.click();
            }
        });
    }

    document.addEventListener('visibilitychange', () => {
        if (! document.hidden) {
            pollStatus();
        }
    });

    if (overlay?.dataset.analysisId) {
        setOverlay({
            id: overlay.dataset.analysisId,
            stage: overlay.dataset.analysisStage,
            progress: Number(overlay.dataset.analysisProgressValue ?? 0),
            message: overlay.dataset.analysisMessageValue,
            error: overlay.dataset.analysisErrorValue,
            started_at: overlay.dataset.analysisStartedAt,
        });
    }

    window.setInterval(updateAnalysisElapsed, 1000);
    window.setInterval(pollStatus, 2000);
}
