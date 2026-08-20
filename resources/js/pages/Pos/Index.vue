<script setup lang="ts">
import Cart from '@/Pos/components/Cart.vue';
import Checkout from '@/Pos/components/Checkout.vue';
import PaymentModal from '@/Pos/components/PaymentModal.vue';
import ProductGrid from '@/Pos/components/ProductGrid.vue';
import { useBarcode } from '@/Pos/composables/useBarcode';
import { useCart } from '@/Pos/composables/useCart';
import { http } from '@/Pos/lib/http';
import { toDecimalString } from '@/Pos/lib/money';
import type { PaymentMethod, PosFeed, PosProduct, QueuedOrder } from '@/Pos/types';
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
 * Everything below talks to /pos/data/* over axios. Inertia's router is never
 * used inside this page — it returns pages and redirects, and neither can be
 * queued and replayed once the offline layer lands in Phase 6.
 */
async function loadFeed() {
    loading.value = true;
    loadError.value = null;

    try {
        const { data } = await http.get<PosFeed>('/products');
        feed.value = data;

        // Remember the till this device sits at, so the cashier is not asked
        // on every shift.
        const remembered = Number(localStorage.getItem('pos.register_id'));
        registerId.value =
            data.registers.find((r) => r.id === remembered)?.id ?? data.registers[0]?.id ?? null;
    } catch {
        loadError.value = 'Could not load the catalogue. Check the connection and try again.';
    } finally {
        loading.value = false;
    }
}

function chooseRegister(id: number) {
    registerId.value = id;
    localStorage.setItem('pos.register_id', String(id));
}

function flash(kind: 'ok' | 'warn', text: string) {
    toast.value = { kind, text };
    setTimeout(() => (toast.value = null), 3000);
}

function addProduct(product: PosProduct) {
    cart.add(product);
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

function buildOrder(payment: {
    method: PaymentMethod;
    amount: number;
    reference: string | null;
}): QueuedOrder {
    return {
        // Generated here, before the network is involved. This is what makes
        // retrying a flush safe: the server collapses duplicates on it.
        client_uuid: crypto.randomUUID(),
        store_id: props.boot.store_id,
        register_id: registerId.value,
        customer_id: cart.customerId,
        created_offline_at: new Date().toISOString(),
        discount_amount: toDecimalString(cart.totals.discount),
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
    };
}

async function completeSale(payment: {
    method: PaymentMethod;
    amount: number;
    reference: string | null;
}) {
    paying.value = true;
    const order = buildOrder(payment);

    try {
        const { data } = await http.post('/orders/sync', { orders: [order] });
        const result = data.results?.[0];

        if (result?.status === 'failed') {
            flash('warn', result.message ?? 'The sale could not be saved.');
            return;
        }

        flash('ok', `Sale ${result?.order_no ?? ''} completed`);
        cart.clear();
        paymentOpen.value = false;

        // Stock hints drift as sales land; refresh quietly in the background.
        void loadFeed();
    } catch {
        flash('warn', 'Could not reach the server. Offline queueing arrives in the next phase.');
    } finally {
        paying.value = false;
    }
}

onMounted(loadFeed);
</script>

<template>
    <Head title="Point of Sale" />

    <div class="flex h-dvh flex-col overflow-hidden bg-background text-foreground">
        <!-- Top bar -->
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

            <div class="ml-auto flex items-center gap-2">
                <!-- A plain anchor, not an Inertia link: leaving /pos should be
                     a clean full load, and Phase 6 gates this when offline. -->
                <a
                    href="/dashboard"
                    class="press flex h-9 items-center gap-1.5 rounded-md border border-border px-3 text-sm text-muted-foreground"
                >
                    <LogOut class="size-4" />
                    <span class="hidden sm:inline">Exit</span>
                </a>
            </div>
        </header>

        <!-- Body -->
        <div v-if="loading" class="flex flex-1 items-center justify-center">
            <LoaderCircle class="size-6 animate-spin text-muted-foreground" />
        </div>

        <div v-else-if="loadError" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
            <CircleAlert class="size-8 text-destructive" />
            <p class="max-w-sm text-sm text-muted-foreground">{{ loadError }}</p>
            <button type="button" class="press h-10 rounded-lg bg-primary px-5 text-sm font-medium text-primary-foreground" @click="loadFeed">
                Try again
            </button>
        </div>

        <main v-else class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <section class="min-h-0 flex-1 lg:border-r lg:border-border">
                <ProductGrid
                    :products="products"
                    :categories="feed!.categories"
                    :currency="currency"
                    @add="addProduct"
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

        <!-- Sale confirmation is the one place expressive motion is allowed. -->
        <Transition
            enter-from-class="opacity-0 translate-y-2"
            enter-active-class="transition duration-200 ease-out-quint"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-150"
        >
            <div
                v-if="toast"
                class="pointer-events-none fixed bottom-5 left-1/2 z-[60] -translate-x-1/2 rounded-full px-5 py-2.5 text-sm font-medium shadow-lg"
                :class="toast.kind === 'ok' ? 'bg-primary text-primary-foreground' : 'bg-destructive text-destructive-foreground'"
                role="status"
            >
                {{ toast.text }}
            </div>
        </Transition>
    </div>
</template>
