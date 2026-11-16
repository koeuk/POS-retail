<script setup lang="ts">
import { Input } from '@/components/ui/input';
import type { CurrencyDef } from '@/composables/useCurrency';
import { useCart } from '@/Pos/composables/useCart';
import { http } from '@/Pos/lib/http';
import { formatMoney } from '@/Pos/lib/money';
import type { SaleType } from '@/Pos/types';
import { Clock, CreditCard, HandCoins, LoaderCircle, Plus, Search, UserRound, Utensils, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Customer {
    id: number;
    name: string;
    phone: string | null;
}

defineProps<{ currency: CurrencyDef }>();
const emit = defineEmits<{
    pay: [];
    'pay-later': [];
    'pick-customer': [];
}>();

const cart = useCart();

/*
 * Three reasons goods leave the shelf, and they mean different things for
 * the money — see App\Enums\SaleType. The chips sit above the total because
 * a cashier decides *why* before deciding *how much*.
 */
const types: { value: SaleType; label: string; icon: typeof UserRound; hint: string }[] = [
    { value: 'customer', label: 'Customer', icon: UserRound, hint: 'A normal sale' },
    { value: 'debt', label: 'In debt', icon: HandCoins, hint: 'A sale on credit — money owed' },
    { value: 'myself', label: 'Myself', icon: Utensils, hint: 'Not a sale' },
];

function choose(t: SaleType) {
    cart.saleType = t;
    // A debt is unusable without someone to owe it, so ask straight away.
    if (t === 'debt' && !cart.customerId) emit('pick-customer');
}

/* ------------------------------------------------------------------ */
/* Optional customer input for Pay now and Pay later                   */
/* ------------------------------------------------------------------ */
const customerQuery = ref('');
const searchResults = ref<Customer[]>([]);
const searchLoading = ref(false);
const dropdownOpen = ref(false);
const dropdownContainer = ref<HTMLElement | null>(null);
let searchDebounce: ReturnType<typeof setTimeout>;

async function searchCustomers() {
    const q = customerQuery.value.trim();
    if (!q) {
        searchResults.value = [];
        return;
    }
    searchLoading.value = true;
    try {
        const { data } = await http.get<Customer[]>('/customers', { params: { q } });
        searchResults.value = data;
    } catch {
        searchResults.value = [];
    } finally {
        searchLoading.value = false;
    }
}

function onQueryChange() {
    dropdownOpen.value = true;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(searchCustomers, 200);
}

function onInputFocus() {
    if (customerQuery.value.trim()) {
        dropdownOpen.value = true;
        void searchCustomers();
    }
}

function selectCustomer(c: Customer) {
    cart.customerId = c.id;
    cart.customerName = c.name;
    customerQuery.value = '';
    searchResults.value = [];
    dropdownOpen.value = false;
}

function clearCustomer() {
    cart.customerId = null;
    cart.customerName = null;
    customerQuery.value = '';
    searchResults.value = [];
    dropdownOpen.value = false;
}

async function quickAddCustomer(name: string) {
    const trimmed = name.trim();
    if (!trimmed) return;
    try {
        const { data } = await http.post<Customer>('/customers', { name: trimmed });
        selectCustomer(data);
    } catch {
        // Handled gracefully
    }
}

async function onEnterCustomer() {
    const q = customerQuery.value.trim();
    if (!q) return;
    if (searchResults.value.length === 1) {
        selectCustomer(searchResults.value[0]);
    } else {
        await quickAddCustomer(q);
    }
}

async function ensureCustomer(): Promise<void> {
    const q = customerQuery.value.trim();
    if (cart.customerId || !q) return;

    try {
        const { data } = await http.get<Customer[]>('/customers', { params: { q } });
        const exact = data.find((c) => c.name.toLowerCase() === q.toLowerCase() || c.phone === q);
        if (exact) {
            selectCustomer(exact);
            return;
        }

        const res = await http.post<Customer>('/customers', { name: q });
        selectCustomer(res.data);
    } catch {
        // Proceed as walk-in if network or storage is unavailable
    }
}

async function onPayLater() {
    await ensureCustomer();
    emit('pay-later');
}

async function onPay() {
    if (cart.saleType === 'customer') {
        await ensureCustomer();
    }
    emit('pay');
}

function onDocumentClick(e: MouseEvent) {
    if (dropdownContainer.value && !dropdownContainer.value.contains(e.target as Node)) {
        dropdownOpen.value = false;
    }
}

watch(
    () => cart.customerId,
    (id) => {
        if (!id) {
            customerQuery.value = '';
        }
    },
);

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    clearTimeout(searchDebounce);
});

/* A debt with nobody attached must not be completable. */
const blocked = computed(() => cart.saleType === 'debt' && !cart.customerId);

const cta = computed(() => {
    switch (cart.saleType) {
        case 'debt':
            return blocked.value ? 'Choose who owes this' : 'Record in debt';
        case 'myself':
            return 'Take for myself';
        default:
            return 'Pay now';
    }
});
</script>

<template>
    <!--
        One number at the till. A cashier reads the figure the customer has to
        pay and nothing else; the line-by-line breakdown belongs on the receipt
        and in Reports, where someone is actually reconciling.
    -->
    <div class="shrink-0 border-t border-border bg-card">
        <div class="space-y-3 p-4">
            <div class="grid grid-cols-3 gap-1.5">
                <button
                    v-for="t in types"
                    :key="t.value"
                    type="button"
                    class="press flex flex-col items-center gap-1 rounded-lg border px-2 py-2 transition-colors"
                    :class="cart.saleType === t.value ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground'"
                    :title="t.hint"
                    :aria-pressed="cart.saleType === t.value"
                    @click="choose(t.value)"
                >
                    <component :is="t.icon" class="size-4" />
                    <span class="text-xs font-medium leading-none">{{ t.label }}</span>
                </button>
            </div>

            <!-- Customer input (optional for Pay now & Pay later) -->
            <div v-if="cart.saleType === 'customer'" ref="dropdownContainer" class="space-y-1.5">
                <div class="flex items-center justify-between text-xs">
                    <span class="flex items-center gap-1.5 font-medium text-muted-foreground">
                        <UserRound class="size-3.5" />
                        Customer
                        <span class="text-[0.7rem] text-muted-foreground/75">(optional)</span>
                    </span>
                    <button
                        v-if="cart.customerId || customerQuery"
                        type="button"
                        class="text-[0.7rem] font-medium text-muted-foreground transition-colors hover:text-destructive"
                        @click="clearCustomer"
                    >
                        Clear (Walk-in)
                    </button>
                </div>

                <!-- If customer is selected from DB -->
                <div
                    v-if="cart.customerId"
                    class="flex items-center justify-between gap-2 rounded-lg border border-primary/40 bg-primary/5 px-2.5 py-1.5 text-xs"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <UserRound class="size-3.5 shrink-0 text-primary" />
                        <span class="truncate font-semibold text-primary">{{ cart.customerName }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            class="press text-[0.7rem] font-medium text-muted-foreground hover:text-primary"
                            @click="emit('pick-customer')"
                        >
                            Change
                        </button>
                        <span class="text-muted-foreground/50">·</span>
                        <button type="button" class="press text-[0.7rem] text-muted-foreground hover:text-destructive" @click="clearCustomer">
                            <X class="size-3" />
                        </button>
                    </div>
                </div>

                <!-- If no customer selected yet: input with live search + create + picker trigger -->
                <div v-else class="relative">
                    <div class="relative flex items-center">
                        <Input
                            v-model="customerQuery"
                            type="text"
                            placeholder="Walk-in · Enter name, table, phone…"
                            class="h-8 pr-16 text-xs"
                            @input="onQueryChange"
                            @focus="onInputFocus"
                            @keydown.enter.prevent="onEnterCustomer"
                        />
                        <div class="absolute right-1 flex items-center gap-0.5">
                            <button
                                v-if="customerQuery"
                                type="button"
                                class="press grid size-6 place-items-center rounded text-muted-foreground hover:text-foreground"
                                title="Clear"
                                @click="customerQuery = ''"
                            >
                                <X class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="press flex h-6 items-center gap-1 rounded px-1.5 text-[0.7rem] font-medium text-primary hover:bg-primary/10"
                                title="Search or add customer"
                                @click="emit('pick-customer')"
                            >
                                <Search class="size-3" />
                            </button>
                        </div>
                    </div>

                    <!-- Dropdown with customer search results & quick-add -->
                    <div
                        v-if="dropdownOpen && (customerQuery.trim().length > 0 || searchResults.length > 0)"
                        class="absolute left-0 right-0 top-full z-30 mt-1 max-h-48 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-lg"
                    >
                        <div v-if="searchLoading" class="flex items-center justify-center p-3 text-xs text-muted-foreground">
                            <LoaderCircle class="mr-1.5 size-3.5 animate-spin" />
                            Searching…
                        </div>
                        <template v-else>
                            <div v-if="searchResults.length" class="space-y-0.5">
                                <button
                                    v-for="c in searchResults"
                                    :key="c.id"
                                    type="button"
                                    class="press flex w-full items-center justify-between rounded px-2.5 py-1.5 text-left text-xs hover:bg-accent"
                                    @click="selectCustomer(c)"
                                >
                                    <span class="truncate font-medium text-foreground">{{ c.name }}</span>
                                    <span v-if="c.phone" class="font-mono text-[0.7rem] text-muted-foreground">{{ c.phone }}</span>
                                </button>
                            </div>
                            <div v-else class="p-2 text-center text-xs text-muted-foreground">No customer found for "{{ customerQuery.trim() }}"</div>

                            <div v-if="customerQuery.trim().length >= 2" class="mt-1 border-t border-border pt-1">
                                <button
                                    type="button"
                                    class="press flex w-full items-center gap-1.5 rounded px-2.5 py-1.5 text-left text-xs font-medium text-primary hover:bg-primary/10"
                                    @click="quickAddCustomer(customerQuery)"
                                >
                                    <Plus class="size-3.5 shrink-0" />
                                    <span>Add "{{ customerQuery.trim() }}" as customer</span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Who the debt is on; tap to change. -->
            <button
                v-if="cart.saleType === 'debt'"
                type="button"
                class="press flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm"
                :class="blocked ? 'border-destructive/50 text-destructive' : 'border-border'"
                @click="emit('pick-customer')"
            >
                <UserRound class="size-4 shrink-0" />
                <span class="min-w-0 flex-1 truncate">{{ cart.customerName ?? 'No customer chosen' }}</span>
                <span class="text-xs text-muted-foreground">Change</span>
            </button>

            <div class="flex items-baseline justify-between">
                <p class="font-display text-base font-semibold">{{ cart.saleType === 'myself' ? 'Value' : 'Total' }}</p>
                <p class="tabular font-mono text-2xl font-bold text-primary">
                    {{ formatMoney(cart.totals.total, currency) }}
                </p>
            </div>

            <!-- Action buttons: Pay later & Pay now in Customer mode -->
            <div v-if="cart.saleType === 'customer'" class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    :disabled="cart.isEmpty"
                    class="press flex h-14 items-center justify-center gap-2 rounded-xl border-2 border-primary/40 bg-background text-sm font-semibold text-primary transition-all hover:border-primary hover:bg-primary/5 disabled:opacity-40"
                    title="Pay later — hold order while drinking or dining"
                    @click="onPayLater"
                >
                    <Clock class="size-5" />
                    Pay later
                </button>

                <button
                    type="button"
                    :disabled="cart.isEmpty"
                    class="press flex h-14 items-center justify-center gap-2 rounded-xl bg-primary text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/95 disabled:opacity-40"
                    @click="onPay"
                >
                    <CreditCard class="size-5" />
                    Pay now
                </button>
            </div>

            <!-- Single action button in Pay later (debt) or Myself mode -->
            <button
                v-else
                type="button"
                :disabled="cart.isEmpty || blocked"
                class="press flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-primary-foreground disabled:opacity-40"
                @click="emit('pay')"
            >
                <component :is="cart.saleType === 'debt' ? HandCoins : CreditCard" class="size-5" />
                {{ cta }}
            </button>
        </div>
    </div>
</template>
