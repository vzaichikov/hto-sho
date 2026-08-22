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

document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (! window.confirm(form.dataset.confirm)) {
            event.preventDefault();
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

            if (sourceFinished || Number(data.state_version) !== initialStateVersion) {
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
