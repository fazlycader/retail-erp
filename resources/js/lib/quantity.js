/**
 * Format integer milli-units (SCALE = 1000) for display without floating-point drift.
 */
export function formatQuantity(milli, unit = '') {
    if (milli === null || milli === undefined) return '—';
    const val = Number(milli) || 0;
    const sign = val < 0 ? '-' : '';
    const abs = Math.abs(val);
    const whole = Math.floor(abs / 1000);
    const rem = abs % 1000;
    const wholeFormatted = new Intl.NumberFormat('en-LK').format(whole);
    const frac = rem === 0 ? '' : `.${String(rem).padStart(3, '0').replace(/0+$/, '')}`;
    const text = `${sign}${wholeFormatted}${frac}`;
    return unit ? `${text} ${unit}` : text;
}

