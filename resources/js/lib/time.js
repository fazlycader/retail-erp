/**
 * "7:30 pm" in Sri Lanka time.
 */
export function clock(iso) {
    return iso ? new Intl.DateTimeFormat('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Colombo' }).format(new Date(iso)) : '';
}
