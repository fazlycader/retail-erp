import { usePage } from '@inertiajs/vue3';

/** App limit for photos and logos (matches ImageUploader::MAX_KB). */
const APP_LIMIT = 5 * 1024 * 1024;

/**
 * Largest file we can send: the app limit, capped by the server's php.ini limit.
 */
export function maxUploadBytes() {
    const server = usePage().props.uploadLimit;

    return Math.min(APP_LIMIT, Number.isFinite(server) && server > 0 ? server : APP_LIMIT);
}

export function describeBytes(bytes) {
    return bytes >= 1048576 ? `${Math.round((bytes / 1048576) * 10) / 10} MB` : `${Math.round(bytes / 1024)} KB`;
}

/**
 * An error message if the file is too large to upload, otherwise null.
 */
export function uploadTooLarge(file) {
    const limit = maxUploadBytes();

    return file && file.size > limit
        ? `This image is ${describeBytes(file.size)}. Please choose one under ${describeBytes(limit)}.`
        : null;
}
