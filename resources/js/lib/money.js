/**
 * Money arrives from the server as integer cents. Only format here; never do
 * arithmetic on the divided value — totals are calculated server-side in cents.
 */
export function formatMoney(cents, currency = 'LKR') {
    const value = Number.isInteger(cents) ? cents : 0;

    const formatted = new Intl.NumberFormat('en-LK', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Math.abs(value) / 100);

    return `${value < 0 ? '-' : ''}${currency} ${formatted}`;
}

/**
 * Parse what a user typed ("1250", "1,250.5") into cents for a live preview only.
 * Returns null when it isn't a valid amount. The server re-parses and validates.
 */
export function decimalToCents(value) {
    const match = String(value ?? '')
        .replace(/[,\s]/g, '')
        .match(/^(\d*)(?:\.(\d{0,2}))?$/);

    if (!match || (match[1] === '' && !match[2])) {
        return null;
    }

    return Number(match[1] || 0) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/**
 * Customer-facing price: "Rs. 1,250" or "Rs. 1,250.50" for LKR (cents only when not .00).
 * Display only, like formatMoney.
 */
export function formatPrice(cents, currency = 'LKR') {
    const value = Number.isInteger(cents) ? cents : 0;
    const hasCents = Math.abs(value) % 100 !== 0;

    const formatted = new Intl.NumberFormat('en-LK', {
        minimumFractionDigits: hasCents ? 2 : 0,
        maximumFractionDigits: 2,
    }).format(Math.abs(value) / 100);

    return `${value < 0 ? '-' : ''}${currency === 'LKR' ? 'Rs.' : currency} ${formatted}`;
}
