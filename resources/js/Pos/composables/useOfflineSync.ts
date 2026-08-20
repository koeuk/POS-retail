import { markAttemptFailed, markSynced, pendingCount, pendingOrders } from '@/Pos/db/dexie';
import { SessionExpired, http, isReallyOnline } from '@/Pos/lib/http';
import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';

/** How often to retry the queue while the app is open. */
const SYNC_INTERVAL_MS = 15_000;

/** Server caps a flush at 200; stay under it. */
const BATCH_SIZE = 100;

interface SyncResult {
    client_uuid: string;
    status: 'created' | 'already_synced' | 'failed';
    order_no: string | null;
    total: string | null;
    message: string | null;
}

/**
 * Drains the offline order queue.
 *
 * The invariant that matters more than anything else here: **a queued order is
 * only ever removed from "pending" when the server has confirmed it.** Not on
 * a timeout, not on a 500, not on an expired session. Every one of those rows
 * is a sale that already happened, and dropping one loses real money.
 */
export function useOfflineSync() {
    const online = ref(navigator.onLine);
    const syncing = ref(false);
    const pending = ref(0);
    const authExpired = ref(false);
    const lastError = ref<string | null>(null);
    const lastSyncedAt = ref<string | null>(null);

    let timer: ReturnType<typeof setInterval> | null = null;

    async function refreshCount() {
        pending.value = await pendingCount();
    }

    /**
     * navigator.onLine only knows whether a network interface is up. On café
     * wifi behind a captive portal it happily reports "online" while every
     * request fails, so the flag is confirmed against the server.
     */
    async function checkConnection(): Promise<boolean> {
        const reachable = await isReallyOnline();
        online.value = reachable;
        return reachable;
    }

    async function flush(): Promise<void> {
        if (syncing.value) return;

        await refreshCount();
        if (pending.value === 0) return;

        if (!(await checkConnection())) return;

        syncing.value = true;

        try {
            const queued = await pendingOrders();

            for (let i = 0; i < queued.length; i += BATCH_SIZE) {
                const batch = queued.slice(i, i + BATCH_SIZE);

                const { data } = await http.post<{ results: SyncResult[] }>('/orders/sync', {
                    orders: batch.map(
                        // Strip the local-only bookkeeping; send just the order.
                        ({ state, order_no, attempts, last_error, receipt, total, ...order }) => order,
                    ),
                });

                for (const result of data.results ?? []) {
                    if (result.status === 'failed') {
                        // Stays pending. A failure is a reason to retry, not
                        // a reason to lose the sale.
                        await markAttemptFailed(result.client_uuid, result.message ?? 'Server rejected the order.');
                        continue;
                    }

                    // 'created' and 'already_synced' are both confirmation
                    // that the server holds this order exactly once.
                    await markSynced(result.client_uuid, result.order_no, result.total);
                }
            }

            authExpired.value = false;
            lastError.value = null;
            lastSyncedAt.value = new Date().toISOString();
        } catch (error) {
            if (error instanceof SessionExpired) {
                // Only a human can fix this. The queue is untouched and the
                // cashier can keep selling in the meantime.
                authExpired.value = true;
                lastError.value = 'Session expired — sign in again to sync.';
            } else {
                online.value = false;
                lastError.value = 'Could not reach the server.';
            }
        } finally {
            syncing.value = false;
            await refreshCount();
        }
    }

    function handleOnline() {
        online.value = true;
        void flush();
    }

    function handleOffline() {
        online.value = false;
    }

    onMounted(async () => {
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        // Belt and braces: the 'online' event fires on reconnection, the
        // interval covers the case where it never fires or the first attempt
        // failed for an unrelated reason.
        timer = setInterval(() => void flush(), SYNC_INTERVAL_MS);

        await refreshCount();
        await checkConnection();
        void flush();
    });

    onBeforeUnmount(() => {
        window.removeEventListener('online', handleOnline);
        window.removeEventListener('offline', handleOffline);
        if (timer) clearInterval(timer);
    });

    return {
        online: readonly(online),
        syncing: readonly(syncing),
        pending: readonly(pending),
        authExpired: readonly(authExpired),
        lastError: readonly(lastError),
        lastSyncedAt: readonly(lastSyncedAt),
        flush,
        refreshCount,
        checkConnection,
    };
}
