import { getJson } from '@/lib/http';
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Polls a sync endpoint for rows that changed since the last cursor (App\Support\SyncVersion,
 * per location) and hands them to onChanges. The endpoint answers
 * { version, has_more, <scope>: [rows…] } for ?since=V&scope[]=… Pauses while the tab is hidden, polls straight away when it comes back, and backs
 * off (up to a minute) while the server or network is failing.
 *
 * Transport sits behind this one function: with REALTIME_DRIVER=pusher and pusher-js loaded on
 * the page (window.Pusher), a push triggers an immediate poll; polling remains the source of truth.
 */
export function useStaffSync({ url, version, scope = [], interval = 4, onChanges, pauseWhenHidden = true }) {
    const cursor = ref(version);
    const online = ref(true);
    const lastSyncAt = ref(Date.now());
    let timer = null;
    let failures = 0;
    let running = false;
    let stopped = false;
    let pusherChannel = null;

    const schedule = (ms) => {
        clearTimeout(timer);

        if (!stopped) {
            timer = setTimeout(tick, ms);
        }
    };

    const delay = () => (failures === 0 ? interval * 1000 : Math.min(60000, interval * 1000 * 2 ** failures));

    async function tick() {
        if (running || stopped || cursor.value === null || cursor.value === undefined) {
            return; // not started yet (start(version) begins polling)
        }

        if (document.hidden && pauseWhenHidden) {
            schedule(delay());

            return;
        }

        running = true;
        const params = new URLSearchParams({ since: String(cursor.value) });
        scope.forEach((s) => params.append('scope[]', s));

        // Phones switching Wi-Fi / mobile data can leave a request hanging forever, which used
        // to stop live updates for good. Give up after 15 s and try again.
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        let result;

        try {
            result = await getJson(`${url}?${params}`, { signal: controller.signal });
        } catch {
            result = { ok: false, status: 0, data: {} };
        } finally {
            clearTimeout(timeout);
            running = false;
        }

        const { ok, status, data } = result;

        if (ok) {
            failures = 0;
            online.value = true;
            lastSyncAt.value = Date.now();

            if (Object.values(data).some((value) => Array.isArray(value) && value.length > 0)) {
                try {
                    onChanges(data);
                } catch (error) {
                    // A screen bug must never stop the polling.
                    console.error(error);
                }
            }

            cursor.value = data.version;
            schedule(data.has_more ? 50 : delay());

            return;
        }

        if (status === 401 || status === 419) {
            // Signed out (e.g. locked from another tab): reload to the login or lock screen.
            window.location.reload();

            return;
        }

        failures += 1;
        online.value = false;
        schedule(delay());
    }

    const now = () => schedule(0);
    /** For screens that load their starting point first (version: null). */
    const start = (version) => {
        cursor.value = version;
        schedule(delay());
    };
    const onVisible = () => !document.hidden && now();

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);
        window.addEventListener('online', now);
        window.addEventListener('pageshow', now);
        schedule(delay());

        const realtime = usePage().props.realtime;

        if (realtime?.driver === 'pusher' && window.Pusher) {
            const pusher = new window.Pusher(realtime.key, { cluster: realtime.cluster });
            pusherChannel = pusher.subscribe(realtime.channel);
            pusherChannel.bind('changed', now);
        }
    });

    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisible);
        window.removeEventListener('focus', onVisible);
        window.removeEventListener('online', now);
        window.removeEventListener('pageshow', now);
        pusherChannel?.unbind_all?.();
    });

    return { cursor, online, lastSyncAt, now, start };
}
