<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Boxes, PackageSearch, Search, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface StockRow {
    id: number;
    qty: number;
    low_stock_threshold: number | null;
    product: { id: number; name: string; sku: string; barcode: string | null; unit: string } | null;
    store: { id: number; name: string } | null;
}

interface Movement {
    id: number;
    type: string;
    qty_change: number;
    note: string | null;
    created_at: string;
    product: { id: number; name: string; unit: string } | null;
    store: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
}

const props = defineProps<{
    stocks: Paginated<StockRow>;
    filters: { search?: string; store_id?: string; state?: string };
    stores: { id: number; name: string }[];
    movements: Movement[];
    summary: { tracked: number; low: number; out: number; oversold: number };
}>();

const ALL = 'all';
const search = ref(props.filters.search ?? '');
const storeId = ref(props.filters.store_id ?? ALL);
const state = ref(props.filters.state ?? ALL);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('inventory.index'),
        {
            search: search.value || undefined,
            store_id: storeId.value === ALL ? undefined : storeId.value,
            state: state.value === ALL ? undefined : state.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch([storeId, state], reload);

/* ------------------------------------------------------------------ */
/* Movement dialog                                                     */
/* ------------------------------------------------------------------ */

const MODES = [
    { value: 'receive', label: 'Receive', hint: 'Goods arrived from a supplier' },
    { value: 'count', label: 'Count', hint: 'Correct the books to the shelf' },
    { value: 'remove', label: 'Remove', hint: 'Damaged, expired or written off' },
    { value: 'return', label: 'Return', hint: 'A customer brought it back' },
] as const;

const adjusting = ref<StockRow | null>(null);
const form = useForm({ stock_id: 0, mode: 'receive' as string, quantity: 0, note: '' });

function openAdjust(stock: StockRow) {
    adjusting.value = stock;
    form.clearErrors();
    form.stock_id = stock.id;
    form.mode = 'receive';
    form.quantity = 0;
    form.note = '';
}

/* The number the shelf will read afterwards. Shown live because a count
   correction is absolute while everything else is a delta, and getting those
   two confused is how stock goes wrong. */
const resulting = computed(() => {
    if (!adjusting.value) return 0;
    const q = Number(form.quantity) || 0;

    switch (form.mode) {
        case 'receive':
        case 'return':
            return adjusting.value.qty + q;
        case 'remove':
            return adjusting.value.qty - q;
        default:
            return q;
    }
});

const delta = computed(() => (adjusting.value ? resulting.value - adjusting.value.qty : 0));

function submitAdjust() {
    form.post(route('inventory.store'), {
        preserveScroll: true,
        onSuccess: () => (adjusting.value = null),
    });
}

/* ------------------------------------------------------------------ */
/* Threshold                                                           */
/* ------------------------------------------------------------------ */

const thresholdFor = ref<StockRow | null>(null);
const thresholdForm = useForm({ stock_id: 0, low_stock_threshold: null as number | null });

function openThreshold(stock: StockRow) {
    thresholdFor.value = stock;
    thresholdForm.clearErrors();
    thresholdForm.stock_id = stock.id;
    thresholdForm.low_stock_threshold = stock.low_stock_threshold;
}

/* The Input wants a string; the column wants null for "no alert". Proxy the
   two so an emptied box clears the threshold rather than storing 0, which
   would mean "alert me at zero" — a very different thing. */
const thresholdInput = computed({
    get: () => (thresholdForm.low_stock_threshold === null ? '' : String(thresholdForm.low_stock_threshold)),
    set: (value: string) => {
        thresholdForm.low_stock_threshold = value === '' ? null : Number(value);
    },
});

function submitThreshold() {
    thresholdForm.put(route('inventory.threshold'), {
        preserveScroll: true,
        onSuccess: () => (thresholdFor.value = null),
    });
}

/* ------------------------------------------------------------------ */

function tone(stock: StockRow) {
    if (stock.qty < 0) return 'text-destructive font-semibold';
    if (stock.qty === 0) return 'text-muted-foreground';
    if (stock.low_stock_threshold !== null && stock.qty <= stock.low_stock_threshold) return 'text-primary';
    return '';
}

const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });

const typeTone = (type: string) => (type === 'sale' ? 'outline' : type === 'restock' || type === 'return' ? 'secondary' : 'default');
</script>

<template>
    <Head title="Inventory" />

    <AppLayout :breadcrumbs="[{ title: 'Inventory', href: '/inventory' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                eyebrow="Catalogue"
                title="Inventory"
                description="Stock is never typed in directly — record what happened and the quantity follows, so every change has a reason attached."
            />

            <div class="stagger mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile label="Tracked" :value="String(summary.tracked)" :icon="Boxes" hint="Product / store rows" />
                <StatTile label="Low stock" :value="String(summary.low)" :icon="TriangleAlert" hint="At or below the alert level" />
                <StatTile label="Out of stock" :value="String(summary.out)" :icon="PackageSearch" hint="Exactly zero on hand" />
                <StatTile
                    label="Oversold"
                    :value="String(summary.oversold)"
                    :icon="TriangleAlert"
                    :tone="summary.oversold > 0 ? 'warning' : 'default'"
                    hint="Below zero — needs a count"
                />
            </div>

            <div class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 60ms">
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name, SKU or barcode…" class="pl-9" autocomplete="off" />
                    </div>

                    <Select v-if="stores.length > 1" v-model="storeId">
                        <SelectTrigger class="w-[11rem]"><SelectValue placeholder="Store" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All stores</SelectItem>
                            <SelectItem v-for="s in stores" :key="s.id" :value="String(s.id)">{{ s.name }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="state">
                        <SelectTrigger class="w-[11rem]"><SelectValue placeholder="Anything" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Anything</SelectItem>
                            <SelectItem value="low">Low stock</SelectItem>
                            <SelectItem value="out">Out of stock</SelectItem>
                            <SelectItem value="oversold">Oversold</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="stocks.data.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Product</TableHead>
                                <TableHead>Store</TableHead>
                                <TableHead data-numeric class="text-right">On hand</TableHead>
                                <TableHead data-numeric class="text-right">Alert at</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="stock in stocks.data" :key="stock.id" class="group">
                                <TableCell>
                                    <p class="font-medium leading-tight">{{ stock.product?.name }}</p>
                                    <p class="tabular font-mono text-xs text-muted-foreground">{{ stock.product?.sku }}</p>
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">{{ stock.store?.name }}</TableCell>
                                <TableCell data-numeric class="text-right">
                                    <span class="tabular font-mono text-base" :class="tone(stock)">{{ stock.qty }}</span>
                                    <span class="ml-1 text-xs text-muted-foreground">{{ stock.product?.unit }}</span>
                                </TableCell>
                                <TableCell data-numeric class="text-right">
                                    <button
                                        type="button"
                                        class="press tabular rounded-md px-2 py-1 font-mono text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                        @click="openThreshold(stock)"
                                    >
                                        {{ stock.low_stock_threshold ?? '—' }}
                                    </button>
                                </TableCell>
                                <TableCell>
                                    <Button size="sm" variant="outline" class="press" @click="openAdjust(stock)">Adjust</Button>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <EmptyState
                    v-else
                    :icon="PackageSearch"
                    title="Nothing to show"
                    description="Try clearing the filters. Only active products appear here."
                />

                <Pagination :links="stocks.links" :from="stocks.from" :to="stocks.to" :total="stocks.total" />
            </div>

            <!-- The ledger. Every row above got here through one of these. -->
            <section class="animate-rise mt-4 rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 120ms">
                <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Recent movements
                </h2>
                <ul v-if="movements.length" class="divide-y divide-border">
                    <li v-for="movement in movements" :key="movement.id" class="flex items-center gap-3 px-4 py-2.5">
                        <Badge :variant="typeTone(movement.type)" class="w-[5.5rem] justify-center capitalize">
                            {{ movement.type }}
                        </Badge>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ movement.product?.name }}</p>
                            <p class="truncate text-[0.7rem] text-muted-foreground">
                                {{ movement.store?.name }} · {{ movement.creator?.name ?? 'System' }} · {{ when(movement.created_at) }}
                                <span v-if="movement.note">· {{ movement.note }}</span>
                            </p>
                        </div>
                        <span
                            class="tabular shrink-0 font-mono text-sm font-semibold"
                            :class="movement.qty_change < 0 ? 'text-destructive' : 'text-primary'"
                        >
                            {{ movement.qty_change > 0 ? '+' : '' }}{{ movement.qty_change }}
                        </span>
                    </li>
                </ul>
                <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">No movements recorded yet.</p>
            </section>
        </div>

        <!-- Record a movement -->
        <Dialog :open="!!adjusting" @update:open="(v) => !v && (adjusting = null)">
            <DialogContent class="max-w-md">
                <form @submit.prevent="submitAdjust">
                    <DialogHeader>
                        <DialogTitle>{{ adjusting?.product?.name }}</DialogTitle>
                        <DialogDescription>
                            {{ adjusting?.store?.name }} · currently <strong class="tabular font-mono">{{ adjusting?.qty }}</strong>
                            {{ adjusting?.product?.unit }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                v-for="m in MODES"
                                :key="m.value"
                                type="button"
                                class="press rounded-lg border px-3 py-2 text-left"
                                :class="form.mode === m.value ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="form.mode = m.value"
                            >
                                <span class="block text-sm font-medium">{{ m.label }}</span>
                                <span class="block text-[0.7rem] leading-tight text-muted-foreground">{{ m.hint }}</span>
                            </button>
                        </div>
                        <InputError :message="form.errors.mode" />

                        <div class="grid gap-2">
                            <Label for="qty">
                                {{ form.mode === 'count' ? 'Counted on the shelf' : 'Quantity' }}
                            </Label>
                            <Input id="qty" v-model="form.quantity" type="number" min="0" inputmode="numeric" class="tabular font-mono" />
                            <InputError :message="form.errors.quantity" />
                        </div>

                        <div class="flex items-baseline justify-between rounded-lg border border-border px-3 py-2 text-sm">
                            <span class="text-muted-foreground">Will be</span>
                            <span>
                                <span class="tabular font-mono text-lg font-semibold" :class="resulting < 0 ? 'text-destructive' : ''">
                                    {{ resulting }}
                                </span>
                                <span
                                    class="tabular ml-2 font-mono text-xs"
                                    :class="delta < 0 ? 'text-destructive' : delta > 0 ? 'text-primary' : 'text-muted-foreground'"
                                >
                                    ({{ delta > 0 ? '+' : '' }}{{ delta }})
                                </span>
                            </span>
                        </div>

                        <div class="grid gap-2">
                            <Label for="note">Note</Label>
                            <Input id="note" v-model="form.note" placeholder="Optional — why this changed" />
                            <InputError :message="form.errors.note" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="adjusting = null">Cancel</Button>
                        <Button type="submit" class="press" :disabled="form.processing">Record movement</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Threshold is a setting, not a movement, so it gets its own small form. -->
        <Dialog :open="!!thresholdFor" @update:open="(v) => !v && (thresholdFor = null)">
            <DialogContent class="max-w-sm">
                <form @submit.prevent="submitThreshold">
                    <DialogHeader>
                        <DialogTitle>Low-stock alert</DialogTitle>
                        <DialogDescription>
                            Flag {{ thresholdFor?.product?.name }} at {{ thresholdFor?.store?.name }} once it drops to this level. Leave blank for no
                            alert.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2 py-5">
                        <Label for="threshold">Alert at</Label>
                        <Input id="threshold" v-model="thresholdInput" type="number" min="0" placeholder="No alert" class="tabular font-mono" />
                        <InputError :message="thresholdForm.errors.low_stock_threshold" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="thresholdFor = null">Cancel</Button>
                        <Button type="submit" class="press" :disabled="thresholdForm.processing">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
