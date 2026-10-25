import { formatMoney } from '@/lib/money';

/**
 * Display a report value by its format (money is integer cents). Display only.
 */
export function formatValue(value, format, currency = 'LKR') {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    switch (format) {
        case 'money':
            return Number.isInteger(value) ? formatMoney(value, currency) : value;
        case 'count':
            return typeof value === 'number' ? value.toLocaleString('en-LK') : value;
        case 'percent':
            return typeof value === 'number' ? `${value.toLocaleString('en-LK', { maximumFractionDigits: 1 })}%` : value;
        case 'minutes':
            return typeof value === 'number' ? `${value.toLocaleString('en-LK', { maximumFractionDigits: 1 })} min` : value;
        case 'quantity':
            return typeof value === 'number' ? (value / 1000).toLocaleString('en-LK', { maximumFractionDigits: 3 }) : value;
        case 'number':
            return typeof value === 'number' ? value.toLocaleString('en-LK', { maximumFractionDigits: 2 }) : value;
        default:
            return value;
    }
}

export const isNumeric = (format) => ['money', 'count', 'percent', 'minutes', 'number', 'quantity'].includes(format);
