<script setup lang="ts">
import Cart from '@/Pos/components/Cart.vue';
import Checkout from '@/Pos/components/Checkout.vue';
import PaymentModal from '@/Pos/components/PaymentModal.vue';
import ProductGrid from '@/Pos/components/ProductGrid.vue';
import SyncStatusBadge from '@/Pos/components/SyncStatusBadge.vue';
import { useBarcode } from '@/Pos/composables/useBarcode';
import { useCart } from '@/Pos/composables/useCart';
import { useOfflineSync } from '@/Pos/composables/useOfflineSync';
import { cacheFeed, queueOrder, readCachedFeed } from '@/Pos/db/dexie';
import { http } from '@/Pos/lib/http';
import { toDecimalString } from '@/Pos/lib/money';
import type { PaymentMethod, PosFeed, PosProduct, StoredOrder } from '@/Pos/types';
import { Head } from '@inertiajs/vue3';
import { CircleAlert, LoaderCircle, LogOut, Store as StoreIcon } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

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

const feed = ref<PosFeed | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const fromCache = ref(false);
const registerId = ref<number | null>(null);
const paying = ref(false);
const paymentOpen = ref(false);
const lastSale = ref<StoredOrder | null>(null);
const toast = ref<{ kind: 'ok' | 'warn'; text: string } | null>(null);

const currency = computed(() => feed.value?.settings.currency_symbol ?? '$');
const products = computed(() => feed.value?.products ?? []);

/**
 * Cache first, network second.
 *
 * The cashier sees the catalogue immediately from Dexie even with no signal;
 * a refresh happens behind them only if the server actually answers.
 */
async function loadFeed() {
    loading.value = true;
    loadError.value = null;

    const cached = await readCachedFeed();

    if (cached) {
        feed.value = cached as PosFeed;
        fromCache.value = true;
        loading.value = false;
    }

    try {
        const { data } = await http.get<PosFeed>('/products');
        feed.value = data;
        fromCache.value = false;
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

    registerId.value =
        registers.find((r) => r.id === remembered)?.id ?? registers[0]?.id ?? null;
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
 * Completing a sale is a **local** operation. The order is written to Dexie
 * and the cart is cleared straight away; reaching the server is a separate,
 * retryable concern. A cashier must never wait on the network to serve the
 * next customer.
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
        // Generated before the network is involved. This is what makes a
        // retry safe: the server collapses duplicates on it.
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
        // Dexie itself failed — this is the one case where the sale cannot be
        // recorded, and the cashier must know before the customer walks off.
        flash('warn', 'Could not save the sale locally. Do not let the customer leave.');
        paying.value = false;
        return;
    }

    lastSale.value = order;
    cart.clear();
    paymentOpen.value = false;
    paying.value = false;
    await sync.refreshCount();

    flash('ok', `Sale saved · ${currency.value}${order.total}`);

    // Try to reach the server immediately; if it fails the order simply stays
    // queued and the 15s loop will pick it up.
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

    <div class="flex h-dvh flex-col overflow-hidden bg-background text-foreground">
        <header class="flex shrink-0 items-center gap-3 border-b border-border px-4 py-2.5">
            <div class="flex min-w-0 items-center gap-2">
                <div class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                    <StoreIcon class="size-4" />
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold leading-tight">{{ boot.store_name }}</p>
                    <p class="truncate font-mono text-[0.65rem] uppercase tracking-wider text-muted-foreground">
                        {{ boot.cashier_name }}
                    </p>
                </div>
            </div>

            <div v-if="feed && feed.registers.length > 1" class="hidden gap-1 sm:flex">
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

            <div class="ml-auto flex items-center gap-2">
                <SyncStatusBadge
                    :online="sync.online.value"
                    :syncing="sync.syncing.value"
                    :pending="sync.pending.value"
                    :auth-expired="sync.authExpired.value"
                    @retry="sync.flush()"
                />

                <!--
                    Nav lockout. Leaving /pos while offline would unload the
                    only page that can reach the queue, and the cashier would
                    be stranded on a dead shell. Queued sales are safe in
                    Dexie either way, but there is no way back until signal
                    returns, so the door is simply closed.
                -->
                <a
                    v-if="sync.online.value && sync.pending.value === 0"
                    href="/dashboard"
                    class="press flex h-9 items-center gap-1.5 rounded-md border border-border px-3 text-sm text-muted-foreground"
                >
                    <LogOut class="size-4" />
                    <span class="hidden sm:inline">Exit</span>
                </a>
                <span
                    v-else
                    class="flex h-9 cursor-not-allowed items-center gap-1.5 rounded-md border border-dashed border-border px-3 text-sm text-muted-foreground/50"
                    :title="
                        sync.online.value
                            ? 'Finish syncing before leaving'
                            : 'Offline — stay here until sales have synced'
                    "
                >
                    <LogOut class="size-4" />
                    <span class="hidden sm:inline">Exit</span>
                </span>
            </div>
        </header>

        <!-- Session expiry keeps selling alive but blocks syncing. -->
        <div
            v-if="sync.authExpired.value"
            class="shrink-0 border-b border-destructive/30 bg-destructive/10 px-4 py-2 text-center text-xs text-destructive"
        >
            Your session expired. Sales are still being saved on this device — sign in again to sync
            them.
            <a href="/login" class="underline">Sign in</a>
        </div>

        <div v-if="loading" class="flex flex-1 items-center justify-center">
            <LoaderCircle class="size-6 animate-spin text-muted-foreground" />
        </div>

        <div v-else-if="loadError" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
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

            <aside class="flex min-h-0 w-full shrink-0 flex-col border-t border-border lg:w-[26rem] lg:border-t-0">
                <Cart :currency="currency" />
                <Checkout :currency="currency" @pay="paymentOpen = true" />
            </aside>
        </main>

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
    </div>
</template>
