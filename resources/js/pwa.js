const SHARE_DATABASE = 'hto-sho-share-target';
const SHARE_STORE = 'pending-shares';
const SHARE_KEY = 'current';
const SHARE_MAX_AGE = 2 * 60 * 60 * 1000;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    });
}

const installBanner = document.querySelector('[data-pwa-install-banner]');
const installButton = installBanner?.querySelector('[data-pwa-install-button]');
let deferredInstallPrompt = null;

const hideInstallBanner = () => {
    if (installBanner) {
        installBanner.hidden = true;
    }

    if (installButton) {
        installButton.disabled = false;
    }
};

window.addEventListener('beforeinstallprompt', (event) => {
    const isInstalled = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    if (! installBanner || ! installButton || isInstalled) {
        return;
    }

    event.preventDefault();
    deferredInstallPrompt = event;
    installBanner.hidden = false;
});

installButton?.addEventListener('click', async () => {
    if (! deferredInstallPrompt) {
        return;
    }

    const installPrompt = deferredInstallPrompt;
    deferredInstallPrompt = null;
    installButton.disabled = true;

    try {
        await installPrompt.prompt();
    } finally {
        hideInstallBanner();
    }
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    hideInstallBanner();
});

const openShareDatabase = () => new Promise((resolve, reject) => {
    const request = indexedDB.open(SHARE_DATABASE, 1);

    request.addEventListener('upgradeneeded', () => {
        if (! request.result.objectStoreNames.contains(SHARE_STORE)) {
            request.result.createObjectStore(SHARE_STORE);
        }
    });
    request.addEventListener('success', () => resolve(request.result));
    request.addEventListener('error', () => reject(request.error));
});

const withShareStore = async (mode, callback) => {
    const database = await openShareDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(SHARE_STORE, mode);
        const store = transaction.objectStore(SHARE_STORE);
        const request = callback(store);

        request.addEventListener('success', () => resolve(request.result));
        request.addEventListener('error', () => reject(request.error));
        transaction.addEventListener('complete', () => database.close());
        transaction.addEventListener('abort', () => reject(transaction.error));
    });
};

const getPendingShare = () => withShareStore('readonly', (store) => store.get(SHARE_KEY));
const clearPendingShare = () => withShareStore('readwrite', (store) => store.delete(SHARE_KEY));

const shareTarget = document.querySelector('[data-share-target]');

if (shareTarget && 'indexedDB' in window) {
    const loading = shareTarget.querySelector('[data-share-loading]');
    const content = shareTarget.querySelector('[data-share-content]');
    const empty = shareTarget.querySelector('[data-share-empty]');
    const emptyMessage = shareTarget.querySelector('[data-share-empty-message]');
    const previews = shareTarget.querySelector('[data-share-previews]');
    const summary = shareTarget.querySelector('[data-share-summary]');
    const status = shareTarget.querySelector('[data-share-status]');
    const discard = shareTarget.querySelector('[data-share-discard]');
    const eventButtons = Array.from(shareTarget.querySelectorAll('[data-share-event]'));
    const queryError = new URLSearchParams(window.location.search).get('share_error');
    let pendingShare = null;

    const clearShareTargetSession = () => fetch(shareTarget.dataset.discardSessionUrl, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        credentials: 'same-origin',
    }).catch(() => {});

    const showStatus = (message = '') => {
        status.textContent = message;
        status.classList.toggle('hidden', message === '');
    };

    const showEmpty = (message = '') => {
        loading.classList.add('hidden');
        content.classList.add('hidden');
        empty.classList.remove('hidden');

        if (message) {
            emptyMessage.textContent = message;
        }
    };

    const setUploading = (uploading, selectedButton = null) => {
        eventButtons.forEach((button) => {
            button.disabled = uploading;
            const label = button.querySelector('[data-share-event-label]');

            if (label) {
                label.textContent = uploading && button === selectedButton
                    ? 'Гусь переносить картинки…'
                    : 'Додати сюди →';
            }
        });
    };

    const renderPendingShare = (share) => {
        previews.replaceChildren();
        share.files.forEach((entry) => {
            const card = document.createElement('figure');
            card.className = 'overflow-hidden rounded-2xl border border-ink/10 bg-white';

            const image = document.createElement('img');
            image.className = 'h-28 w-full object-cover';
            image.alt = '';
            image.src = URL.createObjectURL(entry.blob);
            image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });

            const caption = document.createElement('figcaption');
            caption.className = 'truncate px-3 py-2 text-xs font-semibold';
            caption.textContent = entry.name;
            card.append(image, caption);
            previews.append(card);
        });

        const noun = share.files.length === 1
            ? 'картинка'
            : share.files.length < 5
                ? 'картинки'
                : 'картинок';
        summary.textContent = `${share.files.length} ${noun} · ще не завантажено`;
        loading.classList.add('hidden');
        empty.classList.add('hidden');
        content.classList.remove('hidden');
    };

    const initializeShareTarget = async () => {
        try {
            pendingShare = await getPendingShare();

            if (pendingShare && Date.now() - pendingShare.createdAt > SHARE_MAX_AGE) {
                await clearPendingShare();
                pendingShare = null;
                showEmpty('Картинки чекали понад дві години, тож браузер їх прибрав. Поділіться ними ще раз.');
                clearShareTargetSession();

                return;
            }

            if (! pendingShare?.files?.length) {
                const message = queryError === 'invalid'
                    ? 'Гусь приймає до 10 JPG, PNG або WebP по 8 МБ. Цей пакунок не підійшов.'
                    : queryError === 'storage'
                        ? 'Браузер не зміг тимчасово зберегти картинки. Звільніть трохи місця й спробуйте ще раз.'
                        : '';
                showEmpty(message);
                clearShareTargetSession();

                return;
            }

            renderPendingShare(pendingShare);
        } catch {
            showEmpty('Браузер не дав Гусю прочитати тимчасові картинки. Спробуйте поділитися ними ще раз.');
            clearShareTargetSession();
        }
    };

    discard.addEventListener('click', async () => {
        await Promise.all([
            clearPendingShare().catch(() => {}),
            clearShareTargetSession(),
        ]);
        window.location.assign(shareTarget.dataset.discardUrl);
    });

    eventButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (! pendingShare?.files?.length) {
                showEmpty();

                return;
            }

            setUploading(true, button);
            showStatus();

            try {
                const formData = new FormData();
                pendingShare.files.forEach((entry) => {
                    const file = new File([entry.blob], entry.name, {
                        type: entry.type,
                        lastModified: entry.lastModified,
                    });
                    formData.append('images[]', file);
                });

                const response = await fetch(button.dataset.uploadUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'X-Share-Target': '1',
                    },
                    credentials: 'same-origin',
                    body: formData,
                });
                const payload = await response.json().catch(() => ({}));

                if (! response.ok || ! payload.redirect) {
                    const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
                    throw new Error(validationMessage || payload.message || 'Гусь не зміг додати картинки. Спробуйте ще раз.');
                }

                await clearPendingShare();
                window.location.assign(payload.redirect);
            } catch (error) {
                setUploading(false);
                showStatus(error.message || 'Гусь загубив звʼязок. Картинки лишилися тут — спробуйте ще раз.');
            }
        });
    });

    initializeShareTarget();
} else if (shareTarget) {
    shareTarget.querySelector('[data-share-loading]')?.classList.add('hidden');
    shareTarget.querySelector('[data-share-empty]')?.classList.remove('hidden');
}

const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

if (SpeechRecognition) {
    const liveRegion = document.createElement('p');
    liveRegion.className = 'sr-only';
    liveRegion.setAttribute('aria-live', 'polite');
    document.body.append(liveRegion);

    const microphoneIcon = `
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="9" y="2" width="6" height="12" rx="3"></rect>
            <path d="M5 10v2a7 7 0 0 0 14 0v-2"></path>
            <path d="M12 19v3"></path>
            <path d="M8 22h8"></path>
        </svg>
    `;
    const stopIcon = `
        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <rect x="6" y="6" width="12" height="12" rx="2"></rect>
        </svg>
    `;

    let activeRecognition = null;
    let activeButton = null;

    const resetVoiceButton = (recognition, button) => {
        button.innerHTML = microphoneIcon;
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Надиктувати текст';

        if (activeRecognition === recognition) {
            activeButton = null;
            activeRecognition = null;
        }
    };

    const insertTranscript = (field, transcript) => {
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        const before = field.value.slice(0, start);
        const after = field.value.slice(end);
        const prefix = before !== '' && ! /\s$/.test(before) ? ' ' : '';
        const suffix = after !== '' && ! /^\s|^[,.;:!?]/.test(after) ? ' ' : '';
        const inserted = `${prefix}${transcript.trim()}${suffix}`;
        const maxLength = field.maxLength > 0 ? field.maxLength : Number.POSITIVE_INFINITY;
        field.value = `${before}${inserted}${after}`.slice(0, maxLength);
        const caret = Math.min(before.length + inserted.length, field.value.length);
        field.setSelectionRange?.(caret, caret);
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
        field.focus();
    };

    const enhanceVoiceField = (field) => {
        if (field.dataset.voiceEnhanced === 'true' || field.disabled || field.readOnly || field.dataset.noVoice !== undefined) {
            return;
        }

        const growsToFillSpace = field.classList.contains('flex-1');
        const fillsAvailableWidth = field.classList.contains('w-full');

        field.dataset.voiceEnhanced = 'true';
        field.classList.add('min-h-12', 'w-full', 'pr-14');

        const fieldShell = document.createElement('div');
        fieldShell.className = 'relative';
        fieldShell.dataset.voiceFieldShell = '';

        if (growsToFillSpace) {
            fieldShell.classList.add('min-w-0', 'flex-1');
        }

        if (fillsAvailableWidth) {
            fieldShell.classList.add('w-full');
        }

        field.insertAdjacentElement('beforebegin', fieldShell);
        fieldShell.append(field);

        const button = document.createElement('button');
        button.className = 'absolute bottom-2 right-2 z-10 inline-grid size-9 place-items-center rounded-xl border-2 border-ink bg-paper text-ink shadow-[2px_2px_0_#F7C84B] transition hover:-translate-y-0.5 hover:bg-yellow focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-green disabled:opacity-50';
        button.type = 'button';
        button.innerHTML = microphoneIcon;
        button.title = 'Надиктувати текст';
        button.setAttribute('aria-label', 'Надиктувати текст у це поле');
        button.setAttribute('aria-pressed', 'false');
        fieldShell.append(button);

        button.addEventListener('click', () => {
            if (activeRecognition && activeButton === button) {
                activeRecognition.stop();

                return;
            }

            if (activeRecognition && activeButton) {
                const previousRecognition = activeRecognition;
                const previousButton = activeButton;
                previousRecognition.stop();
                resetVoiceButton(previousRecognition, previousButton);
            }

            const recognition = new SpeechRecognition();
            activeRecognition = recognition;
            activeButton = button;
            recognition.lang = 'uk-UA';
            recognition.continuous = false;
            recognition.interimResults = false;

            recognition.addEventListener('start', () => {
                button.innerHTML = stopIcon;
                button.setAttribute('aria-pressed', 'true');
                button.title = 'Зупинити диктування';
                liveRegion.textContent = 'Слухаю. Говоріть.';
            });
            recognition.addEventListener('result', (event) => {
                const transcript = Array.from(event.results)
                    .filter((result) => result.isFinal)
                    .map((result) => result[0]?.transcript ?? '')
                    .join(' ');

                if (transcript.trim()) {
                    insertTranscript(field, transcript);
                    liveRegion.textContent = 'Готово. Текст додано в поле.';
                }
            });
            recognition.addEventListener('error', (event) => {
                liveRegion.textContent = event.error === 'not-allowed'
                    ? 'Браузер не дав доступу до мікрофона.'
                    : 'Не вдалося розпізнати голос. Спробуйте ще раз.';
            });
            recognition.addEventListener('end', () => resetVoiceButton(recognition, button));

            try {
                recognition.start();
            } catch {
                resetVoiceButton(recognition, button);
            }
        });
    };

    const enhanceVoiceFields = (root) => {
        const selector = 'input:not([type]), input[type="text"], input[type="search"], textarea';

        if (root.matches?.(selector)) {
            enhanceVoiceField(root);
        }

        root.querySelectorAll?.(selector).forEach(enhanceVoiceField);
    };

    enhanceVoiceFields(document);
    new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (node instanceof Element) {
                enhanceVoiceFields(node);
            }
        }));
    }).observe(document.body, { childList: true, subtree: true });
}
