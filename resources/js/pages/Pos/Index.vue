<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Cart from '@/Pos/components/Cart.vue';
import Checkout from '@/Pos/components/Checkout.vue';
import PaymentModal from '@/Pos/components/PaymentModal.vue';
import ProductGrid from '@/Pos/components/ProductGrid.vue';
import SyncStatusBadge from '@/Pos/components/SyncStatusBadge.vue';
import { useBarcode } from '@/Pos/composables/useBarcode';
import { useCart } from '@/Pos/composables/useCart';
import { useNavLock } from '@/Pos/composables/useNavLock';
import { useOfflineSync } from '@/Pos/composables/useOfflineSync';
import { cacheFeed, queueOrder, readCachedFeed } from '@/Pos/db/dexie';
import { http } from '@/Pos/lib/http';
import { toDecimalString } from '@/Pos/lib/money';
import type { PaymentMethod, PosFeed, StoredOrder } from '@/Pos/types';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, LoaderCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';

const props = defineProps<{
    boot: {
        store_id: number;
        store_name: string;
        cashier_id: number;
        cashier_name: string;
    };
}>();

const cart = useCart();
const sync = useOfflineSync();
const navLock = useNavLock();

const feed = ref<PosFeed | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const registerId = ref<number | null>(null);
const paying = ref(false);
const paymentOpen = ref(false);
const toast = ref<{ kind: 'ok' | 'warn'; text: string } | null>(null);

const currency = computed(() => feed.value?.settings.currency_symbol ?? '$');
const products = computed(() => feed.value?.products ?? []);

/*
 * Close the door whenever there is unsynced work. Navigating away would
 * unload the page that owns the flush loop, leaving real sales sitting in
 * IndexedDB with nothing driving them to the server.
 */
watchEffect(() => {
    if (!sync.online.value) {
        navLock.lock('Offline — stay here until sales have synced');
    } else if (sync.pending.value > 0) {
        navLock.lock(`${sync.pending.value} sale(s) still syncing`);
    } else {
        navLock.unlock();
    }
});

onBeforeUnmount(() => navLock.unlock());

/**
 * Cache first, network second: the catalogue paints instantly from Dexie
 * even with no signal, and refreshes behind the cashier only if the server
 * actually answers.
 */
async function loadFeed() {
    loading.value = true;
    loadError.value = null;

    const cached = await readCachedFeed();

    if (cached) {
        feed.value = cached as PosFeed;
        loading.value = false;
    }

    try {
        const { data } = await http.get<PosFeed>('/products');
        feed.value = data;
        await cacheFeed(data);
    } catch {
        if (!cached) {
            loadError.value =
                'No catalogue yet, and the server is unreachable. Connect once to set this device up.';
        }
    } finally {
        loading.value = false;
        pickRegister();
    }
}

function pickRegister() {
    const registers = feed.value?.registers ?? [];
    const remembered = Number(localStorage.getItem('pos.register_id'));

    registerId.value = registers.find((r) => r.id === remembered)?.id ?? registers[0]?.id ?? null;
}

function chooseRegister(id: number) {
    registerId.value = id;
    localStorage.setItem('pos.register_id', String(id));
}

function flash(kind: 'ok' | 'warn', text: string) {
    toast.value = { kind, text };
    setTimeout(() => (toast.value = null), 3200);
}

useBarcode({
    onScan: (code) => {
        const match = products.value.find((p) => p.barcode === code || p.sku === code);

        if (match) {
            cart.add(match);
            return;
        }

        flash('warn', `No product with code ${code}`);
    },
});

/**
 * Completing a sale is a **local** operation. The order goes into Dexie and
 * the cart clears straight away; reaching the server is a separate, retryable
 * concern. A cashier must never wait on the network to serve the next person.
 */
async function completeSale(payment: {
    method: PaymentMethod;
    amount: number;
    reference: string | null;
}) {
    paying.value = true;

    const totals = cart.totals;
    const change = payment.method === 'cash' ? Math.max(0, payment.amount - totals.total) : 0;

    const order: StoredOrder = {
        // Generated before the network is involved — this is what makes a
        // retry safe, because the server collapses duplicates on it.
        client_uuid: crypto.randomUUID(),
        store_id: props.boot.store_id,
        register_id: registerId.value,
        customer_id: cart.customerId,
        created_offline_at: new Date().toISOString(),
        discount_amount: toDecimalString(totals.discount),
        items: cart.lines.map((line) => ({
            product_id: line.productId,
            product_name: line.name,
            qty: line.qty,
            unit_price: toDecimalString(line.unitPrice),
            discount: toDecimalString(line.discount),
            tax_rate: line.taxRate,
        })),
        payments: [
            {
                method: payment.method,
                amount: toDecimalString(payment.amount),
                reference_no: payment.reference,
            },
        ],
        state: 'pending_sync',
        order_no: null,
        total: toDecimalString(totals.total),
        attempts: 0,
        last_error: null,
        receipt: {
            subtotal: toDecimalString(totals.subtotal),
            tax: toDecimalString(totals.tax),
            total: toDecimalString(totals.total),
            paid: toDecimalString(payment.amount),
            change: toDecimalString(change),
            cashier: props.boot.cashier_name,
            store: props.boot.store_name,
        },
    };

    try {
        await queueOrder(order);
    } catch {
        // IndexedDB itself failed. This is the only case where a sale cannot
        // be recorded, and the cashier must know before the customer leaves.
        flash('warn', 'Could not save the sale locally. Do not let the customer leave.');
        paying.value = false;
        return;
    }

    cart.clear();
    paymentOpen.value = false;
    paying.value = false;
    await sync.refreshCount();

    flash('ok', `Sale saved · ${currency.value}${order.total}`);

    void sync.flush().then(() => {
        if (sync.online.value) void refreshStockHints();
    });
}

/** Stock hints drift as sales land; refresh quietly, never blocking. */
async function refreshStockHints() {
    try {
        const { data } = await http.get<PosFeed>('/products');
        feed.value = data;
        await cacheFeed(data);
    } catch {
        /* a stale hint is harmless — the server is the ledger */
    }
}

onMounted(loadFeed);
</script>

<template>
    <Head title="Point of Sale" />

    <AppLayout :breadcrumbs="[{ title: 'Point of Sale', href: '/pos' }]">
        <!-- Fills the viewport under the layout chrome. The grid and cart each
             scroll internally so the page itself never does — a till that
             scrolls as a whole is miserable on a tablet. -->
        <div class="flex h-[calc(100dvh-9rem)] min-h-[28rem] flex-col md:h-[calc(100dvh-4rem)]">
            <!-- POS toolbar -->
            <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-border px-4 py-2">
                <div v-if="feed && feed.registers.length > 1" class="flex gap-1">
                    <button
                        v-for="r in feed.registers"
                        :key="r.id"
                        type="button"
                        class="press h-8 rounded-md border px-2.5 text-xs font-medium"
                        :class="
                            registerId === r.id
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground'
                        "
                        @click="chooseRegister(r.id)"
                    >
                        {{ r.name }}
                    </button>
                </div>
                <p v-else-if="feed?.registers.length" class="font-mono text-xs text-muted-foreground">
                    {{ feed.registers[0].name }}
                </p>

                <div class="ml-auto">
                    <SyncStatusBadge
                        :online="sync.online.value"
                        :syncing="sync.syncing.value"
                        :pending="sync.pending.value"
                        :auth-expired="sync.authExpired.value"
                        @retry="sync.flush()"
                    />
                </div>
            </div>

            <div
                v-if="sync.authExpired.value"
                class="shrink-0 border-b border-destructive/30 bg-destructive/10 px-4 py-2 text-center text-xs text-destructive"
            >
                Your session expired. Sales are still saved on this device —
                <a href="/login" class="underline">sign in again</a> to sync them.
            </div>

            <div v-if="loading" class="flex flex-1 items-center justify-center">
                <LoaderCircle class="size-6 animate-spin text-muted-foreground" />
            </div>

            <div
                v-else-if="loadError"
                class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center"
            >
                <CircleAlert class="size-8 text-destructive" />
                <p class="max-w-sm text-sm text-muted-foreground">{{ loadError }}</p>
                <button
                    type="button"
                    class="press h-10 rounded-lg bg-primary px-5 text-sm font-medium text-primary-foreground"
                    @click="loadFeed"
                >
                    Try again
                </button>
            </div>

            <main v-else class="flex min-h-0 flex-1 flex-col lg:flex-row">
                <section class="min-h-0 flex-1 lg:border-r lg:border-border">
                    <ProductGrid
                        :products="products"
                        :categories="feed!.categories"
                        :currency="currency"
                        @add="cart.add($event)"
                    />
                </section>

                <aside
                    class="flex min-h-0 w-full shrink-0 flex-col border-t border-border lg:w-[24rem] lg:border-t-0"
                >
                    <Cart :currency="currency" />
                    <Checkout :currency="currency" @pay="paymentOpen = true" />
                </aside>
            </main>
        </div>

        <PaymentModal
            :open="paymentOpen"
            :total="cart.totals.total"
            :currency="currency"
            :busy="paying"
            @close="paymentOpen = false"
            @confirm="completeSale"
        />

        <Transition
            enter-from-class="opacity-0 translate-y-2"
            enter-active-class="transition duration-200 ease-out-quint"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-150"
        >
            <div
                v-if="toast"
                class="pointer-events-none fixed bottom-5 left-1/2 z-[60] -translate-x-1/2 rounded-full px-5 py-2.5 text-sm font-medium shadow-lg"
                :class="
                    toast.kind === 'ok'
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-destructive text-destructive-foreground'
                "
                role="status"
            >
                {{ toast.text }}
            </div>
        </Transition>
    </AppLayout>
</template>
