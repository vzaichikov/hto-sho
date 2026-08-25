const SHARE_DATABASE = 'hto-sho-share-target';
const SHARE_STORE = 'pending-shares';
const SHARE_KEY = 'current';
const SHARE_TARGET_PATH = '/share-target';
const ALLOWED_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const MAX_IMAGE_COUNT = 10;
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

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

const clearPendingShare = () => withShareStore('readwrite', (store) => store.delete(SHARE_KEY));

const redirectToChooser = (error = null) => {
    const target = new URL(SHARE_TARGET_PATH, self.location.origin);
    target.searchParams.set('shared', '1');

    if (error) {
        target.searchParams.set('share_error', error);
    }

    return Response.redirect(target.href, 303);
};

const handleShareTarget = async (request) => {
    try {
        await clearPendingShare();
        const formData = await request.formData();
        const files = formData.getAll('images').filter((entry) => entry instanceof Blob);
        const invalid = files.length === 0
            || files.length > MAX_IMAGE_COUNT
            || files.some((file) => ! ALLOWED_IMAGE_TYPES.has(file.type) || file.size > MAX_IMAGE_BYTES);

        if (invalid) {
            return redirectToChooser('invalid');
        }

        await withShareStore('readwrite', (store) => store.put({
            createdAt: Date.now(),
            files: files.map((file, index) => ({
                blob: file,
                name: file.name || `shared-image-${index + 1}`,
                type: file.type,
                lastModified: file.lastModified || Date.now(),
                size: file.size,
            })),
        }, SHARE_KEY));

        return redirectToChooser();
    } catch {
        return redirectToChooser('storage');
    }
};

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method === 'POST' && url.origin === self.location.origin && url.pathname === SHARE_TARGET_PATH) {
        event.respondWith(handleShareTarget(event.request));
    }
});
