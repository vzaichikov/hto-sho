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
        const pastedImages = Array.from(event.clipboardData?.files ?? []).filter((file) => file.type.startsWith('image/'));

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

if (workspace?.dataset.eventStatus === 'processing') {
    const initialStatus = workspace.dataset.eventStatus;
    const initialVersion = Number(workspace.dataset.eventStateVersion);
    const initialUpdatedAt = workspace.dataset.eventUpdatedAt;

    const pollStatus = async () => {
        try {
            const response = await fetch(workspace.dataset.eventStatusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                return;
            }

            const data = await response.json();

            if (data.status !== initialStatus || Number(data.state_version) !== initialVersion || data.updated_at !== initialUpdatedAt) {
                window.location.reload();
            }
        } catch {
            // A transient polling failure should not interrupt the composer.
        }
    };

    window.setInterval(pollStatus, 3000);
}
