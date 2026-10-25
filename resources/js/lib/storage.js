/**
 * localStorage that never throws (private mode, blocked storage, quota). Values are JSON.
 */
export function readStore(key, fallback = null) {
    try {
        const raw = window.localStorage.getItem(key);

        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function writeStore(key, value) {
    try {
        if (value === null || value === undefined) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify(value));
        }
    } catch {
        // Storage unavailable: the app keeps working in memory.
    }
}

/**
 * Random hex id that works on plain http too (crypto.randomUUID needs a secure context).
 */
export function randomId(bytes = 16) {
    const values = new Uint8Array(bytes);
    window.crypto.getRandomValues(values);

    return Array.from(values, (b) => b.toString(16).padStart(2, '0')).join('');
}
