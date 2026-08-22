const sourceComposer = document.querySelector('[data-source-composer]');

if (sourceComposer) {
    const input = sourceComposer.querySelector('[data-file-input]');
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

    input.addEventListener('change', renderPreviews);

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
    const stepPanels = Array.from(eventCreateForm.querySelectorAll('[data-create-step]'));
    const stepIndicators = Array.from(document.querySelectorAll('[data-create-step-indicator]'));
    const stepLine = document.querySelector('[data-create-step-line]');
    const stepLabel = document.querySelector('[data-create-step-label]');
    const checkingPanel = eventCreateForm.querySelector('[data-create-checking]');
    const submitButton = eventCreateForm.querySelector('[data-create-submit]');
    const requestError = eventCreateForm.querySelector('[data-create-request-error]');
    const serverError = document.querySelector('[data-create-server-error]');
    const descriptionCount = eventCreateForm.querySelector('[data-create-description-count]');
    let currentStep = Number(eventCreateForm.dataset.initialStep) === 2 ? 2 : 1;

    const fieldError = (field) => eventCreateForm.querySelector(`[data-create-error="${field}"]`);

    const setFieldError = (field, message = '') => {
        const error = fieldError(field);
        const input = field === 'title' ? titleInput : descriptionInput;

        if (error) {
            error.textContent = message;
        }

        input.toggleAttribute('aria-invalid', message !== '');
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
                setFieldError('title', titleMessage);
                setFieldError('description', descriptionMessage);
                setStep(titleMessage ? 1 : 2);
                (titleMessage ? titleInput : descriptionInput).focus();

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
const silpoDialogButton = document.querySelector('[data-silpo-dialog-open]');

if (silpoDialog instanceof HTMLDialogElement && silpoDialogButton) {
    silpoDialogButton.addEventListener('click', () => silpoDialog.showModal());
    silpoDialog.addEventListener('click', (event) => {
        if (event.target === silpoDialog) {
            silpoDialog.close('cancel');
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
    const analysisForm = document.querySelector('[data-analysis-form]');
    const analysisButton = document.querySelector('[data-analysis-button]');
    const minimizeButton = document.querySelector('[data-analysis-minimize]');
    const initialStateVersion = Number(workspace.dataset.eventStateVersion);
    const initialPlanStatus = workspace.dataset.eventPlanStatus;

    const setOverlay = (task) => {
        if (! overlay || ! task) {
            overlay?.classList.add('hidden');

            return;
        }

        const active = ['waiting_for_quiet', 'waiting_for_images', 'summarizing'].includes(task.stage);
        const failed = task.stage === 'failed';
        overlay.classList.toggle('hidden', ! active && ! failed);
        overlay.querySelector('[data-analysis-message]').textContent = task.error || task.message || '';
        overlay.querySelector('[data-analysis-progress]').style.width = `${task.progress ?? 0}%`;
        overlay.querySelector('[data-analysis-progress-label]').textContent = task.progress ?? 0;
        overlay.querySelector('img')?.classList.toggle('goose-working', active);

        if (analysisButton) {
            analysisButton.disabled = active;
            analysisButton.textContent = active ? 'Гусь уже гребе…' : 'Гусь, розгреби все';
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

    analysisForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        analysisButton.disabled = true;
        analysisButton.textContent = 'Гусь уже гребе…';
        overlay?.classList.remove('hidden');

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
            setOverlay({ ...task, progress: task.stage === 'waiting_for_quiet' ? 10 : 20 });
            window.setTimeout(pollStatus, 250);
        } catch {
            analysisButton.disabled = false;
            analysisButton.textContent = 'Спробувати ще раз';

            if (overlay) {
                overlay.classList.remove('hidden');
                overlay.querySelector('[data-analysis-message]').textContent = 'Гусь навіть не стартував. Перевірте з’єднання і повторіть.';
            }
        }
    });

    minimizeButton?.addEventListener('click', () => {
        const minimized = overlay.dataset.minimized !== 'true';
        overlay.dataset.minimized = String(minimized);
        minimizeButton.textContent = minimized ? '+' : '−';
        minimizeButton.setAttribute('aria-label', minimized ? 'Розгорнути прогрес' : 'Згорнути прогрес');
    });

    document.addEventListener('visibilitychange', () => {
        if (! document.hidden) {
            pollStatus();
        }
    });

    window.setInterval(pollStatus, 2000);
}
