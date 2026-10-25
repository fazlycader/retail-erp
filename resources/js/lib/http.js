/**
 * Small fetch wrapper for staff-screen JSON endpoints (polling, quick lookups) (no axios). Sends Laravel's CSRF token
 * from the XSRF-TOKEN cookie. Resolves to { ok, status, data }; never throws for HTTP errors.
 */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export const csrfToken = xsrfToken;

export async function requestJson(url, { method = 'GET', body = null, signal } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (body !== null) {
        headers['Content-Type'] = 'application/json';
    }

    let response;

    try {
        response = await fetch(url, { method, headers, body: body === null ? null : JSON.stringify(body), credentials: 'same-origin', signal });
    } catch (error) {
        if (error.name === 'AbortError') {
            throw error;
        }

        return { ok: false, status: 0, data: { message: "We couldn't reach the server. Check your connection and try again." } };
    }

    let data = {};

    try {
        data = await response.json();
    } catch {
        //
    }

    if (response.status === 419) {
        data.message = 'Your session expired. Please refresh the page and try again.';
    }

    return { ok: response.ok, status: response.status, data };
}

export const postJson = (url, body, options = {}) => requestJson(url, { ...options, method: 'POST', body });
export const getJson = (url, options = {}) => requestJson(url, options);
