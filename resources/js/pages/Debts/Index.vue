<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Eye, HandCoins, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Debt {
    id: number;
    order_no: string;
    total: string;
    paid_amount: string;
    items_count: number;
    created_at: string;
    created_offline_at: string | null;
    customer: { id: number; name: string; phone: string | null } | null;
    cashier: { id: number; name: string } | null;
    items: { id: number; product_name: string; qty: number; unit_price: string; subtotal: string }[];
    payments: { id: number; method: string; amount: string; reference_no: string | null; created_at: string }[];
}

const props = defineProps<{
    debts: Paginated<Debt>;
    filters: { search: string; state: string };
    summary: { open_count: number; owed: string };
    methods: { value: string; label: string }[];
}>();

const { money } = useCurrency();

const search = ref(props.filters.search);
const state = ref(props.filters.state);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('debts.index'),
        { search: search.value || undefined, state: state.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch(state, reload);

const owed = (d: Debt) => Math.max(0, Number(d.total) - Number(d.paid_amount));

const soldAt = (d: Debt) => new Date(d.created_offline_at ?? d.created_at).toLocaleDateString(undefined, { dateStyle: 'medium' });

/* Details sheet: what they took and what they have paid, without leaving
   the list. A debt is a conversation at the counter, and the counter needs
   the whole story on one screen. */
const viewing = ref<Debt | null>(null);

const paidAt = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const methodLabel = (m: string) => (m === 'qr' ? 'QR' : m.charAt(0).toUpperCase() + m.slice(1));

/* Settle dialog. Defaults to the full amount owed — most people pay it off. */
const settling = ref<Debt | null>(null);
const form = useForm({ amount: '', method: 'cash', reference_no: '' });

function openSettle(d: Debt) {
    settling.value = d;
    form.clearErrors();
    form.amount = owed(d).toFixed(2);
    form.method = 'cash';
    form.reference_no = '';
}

const leftAfter = computed(() => (settling.value ? Math.max(0, owed(settling.value) - (Number(form.amount) || 0)) : 0));

function submitSettle() {
    if (!settling.value) return;
    form.post(route('debts.settle', { order: settling.value.id }), {
        preserveScroll: true,
        onSuccess: () => (settling.value = null),
    });
}
</script>

<template>
    <Head title="In Debt" />

    <AppLayout :breadcrumbs="[{ title: 'In Debt', href: '/debts' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                eyebrow="Selling"
                title="In Debt"
                description="Sales made on credit. Record money as it comes in; a debt is settled once it is paid in full."
            />

            <div class="stagger mb-4 grid gap-4 sm:grid-cols-2">
                <StatTile
                    label="Still owed"
                    :value="money(summary.owed)"
                    :icon="HandCoins"
                    :tone="Number(summary.owed) > 0 ? 'warning' : 'default'"
                />
                <StatTile label="Open debts" :value="String(summary.open_count)" :icon="HandCoins" hint="Customers who still owe something" />
            </div>

            <div class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 60ms">
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Customer, phone or order no…" class="pl-9" autocomplete="off" />
                    </div>
                    <Select v-model="state">
                        <SelectTrigger class="w-[10rem]"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="open">Still owed</SelectItem>
                            <SelectItem value="settled">Settled</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="debts.data.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Customer</TableHead>
                                <TableHead>Order</TableHead>
                                <TableHead>When</TableHead>
                                <TableHead data-numeric class="text-right">Total</TableHead>
                                <TableHead data-numeric class="text-right">Paid</TableHead>
                                <TableHead data-numeric class="text-right">Owed</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="d in debts.data" :key="d.id">
                                <TableCell>
                                    <p class="font-medium leading-tight">{{ d.customer?.name ?? '—' }}</p>
                                    <p v-if="d.customer?.phone" class="tabular font-mono text-xs text-muted-foreground">{{ d.customer.phone }}</p>
                                </TableCell>
                                <TableCell>
                                    <Link :href="route('orders.show', { order: d.id })" class="tabular font-mono text-xs hover:underline">{{
                                        d.order_no
                                    }}</Link>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-sm text-muted-foreground">{{ soldAt(d) }}</TableCell>
                                <TableCell data-numeric class="text-right"><Money :value="d.total" /></TableCell>
                                <TableCell data-numeric class="text-right text-muted-foreground"><Money :value="d.paid_amount" /></TableCell>
                                <TableCell data-numeric class="text-right">
                                    <Badge v-if="owed(d) <= 0" variant="secondary">Settled</Badge>
                                    <span v-else class="tabular font-mono font-semibold text-destructive">{{ money(owed(d)) }}</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8"
                                            :aria-label="`View ${d.order_no}`"
                                            title="View details"
                                            @click="viewing = d"
                                        >
                                            <Eye class="size-4" />
                                        </Button>
                                        <Button v-if="owed(d) > 0" size="sm" class="press" @click="openSettle(d)">Record payment</Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <EmptyState
                    v-else
                    :icon="HandCoins"
                    :title="state === 'open' ? 'Nobody owes you anything' : 'No settled debts yet'"
                    description="Choose “In debt” at the till to record a sale on credit."
                />

                <Pagination :links="debts.links" :from="debts.from" :to="debts.to" :total="debts.total" :per-page="debts.per_page" />
            </div>
        </div>

        <!-- Details: the items they took, and every payment so far. -->
        <Sheet :open="!!viewing" @update:open="(v) => !v && (viewing = null)">
            <SheetContent side="right" class="flex w-full flex-col p-0 sm:max-w-lg">
                <SheetHeader class="shrink-0 border-b border-border px-5 pb-4 pt-5 text-left">
                    <SheetTitle>{{ viewing?.customer?.name ?? 'Debt' }}</SheetTitle>
                    <SheetDescription>
                        <span class="tabular font-mono">{{ viewing?.order_no }}</span>
                        <span v-if="viewing"> · {{ soldAt(viewing) }}</span>
                        <span v-if="viewing?.customer?.phone" class="tabular font-mono"> · {{ viewing.customer.phone }}</span>
                    </SheetDescription>
                </SheetHeader>

                <div v-if="viewing" class="min-h-0 flex-1 overflow-y-auto">
                    <!-- Balance, first: it is the number the conversation is about. -->
                    <div class="grid grid-cols-3 divide-x divide-border border-b border-border">
                        <div class="px-4 py-3">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Total</p>
                            <p class="tabular font-mono text-lg font-semibold">{{ money(viewing.total) }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Paid</p>
                            <p class="tabular font-mono text-lg font-semibold">{{ money(viewing.paid_amount) }}</p>
                        </div>
                        <div class="px-4 py-3" :class="owed(viewing) > 0 ? 'bg-destructive/5' : ''">
                            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">Owed</p>
                            <p class="tabular font-mono text-lg font-bold" :class="owed(viewing) > 0 ? 'text-destructive' : 'text-primary'">
                                {{ owed(viewing) > 0 ? money(owed(viewing)) : 'Settled' }}
                            </p>
                        </div>
                    </div>

                    <!-- What they took. -->
                    <section>
                        <h3
                            class="border-b border-border px-5 py-2.5 font-display text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                            Items ({{ viewing.items.length }})
                        </h3>
                        <ul class="divide-y divide-border">
                            <li v-for="item in viewing.items" :key="item.id" class="flex items-center gap-3 px-5 py-2.5">
                                <span class="tabular w-8 shrink-0 font-mono text-sm text-muted-foreground">{{ item.qty }}×</span>
                                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ item.product_name }}</span>
                                <span class="tabular shrink-0 font-mono text-xs text-muted-foreground">{{ money(item.unit_price) }}</span>
                                <span class="tabular w-24 shrink-0 text-right font-mono text-sm font-semibold">{{ money(item.subtotal) }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- What they have paid so far. -->
                    <section>
                        <h3
                            class="border-b border-t border-border px-5 py-2.5 font-display text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                            Payments ({{ viewing.payments.length }})
                        </h3>
                        <ul v-if="viewing.payments.length" class="divide-y divide-border">
                            <li v-for="pay in viewing.payments" :key="pay.id" class="flex items-center gap-3 px-5 py-2.5">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{ methodLabel(pay.method) }}</span>
                                    <span class="block text-[0.7rem] text-muted-foreground">
                                        {{ paidAt(pay.created_at)
                                        }}<span v-if="pay.reference_no" class="tabular font-mono"> · {{ pay.reference_no }}</span>
                                    </span>
                                </span>
                                <span class="tabular shrink-0 font-mono text-sm font-semibold text-primary">{{ money(pay.amount) }}</span>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-4 text-sm text-muted-foreground">Nothing paid yet.</p>
                    </section>
                </div>

                <div v-if="viewing" class="flex shrink-0 items-center gap-2 border-t border-border p-4">
                    <Button as-child variant="outline" class="press">
                        <Link :href="route('orders.show', { order: viewing.id })">Full order</Link>
                    </Button>
                    <Button
                        v-if="owed(viewing) > 0"
                        class="press flex-1"
                        @click="
                            openSettle(viewing);
                            viewing = null;
                        "
                        >Record payment</Button
                    >
                </div>
            </SheetContent>
        </Sheet>

        <Dialog :open="!!settling" @update:open="(v) => !v && (settling = null)">
            <DialogContent class="max-w-sm">
                <form @submit.prevent="submitSettle">
                    <DialogHeader>
                        <DialogTitle>Record a payment</DialogTitle>
                        <DialogDescription>
                            {{ settling?.customer?.name }} owes
                            <strong class="tabular font-mono">{{ money(settling ? owed(settling) : 0) }}</strong> on {{ settling?.order_no }}.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="amt">Amount received</Label>
                            <Input
                                id="amt"
                                v-model="form.amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                inputmode="decimal"
                                class="tabular font-mono"
                            />
                            <InputError :message="form.errors.amount" />
                            <p class="text-xs text-muted-foreground">
                                <template v-if="leftAfter > 0"
                                    >Still owed after this: <strong class="tabular font-mono">{{ money(leftAfter) }}</strong></template
                                >
                                <template v-else>This settles the debt in full.</template>
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label>Paid by</Label>
                            <Select v-model="form.method">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.method" />
                        </div>
                        <div v-if="form.method !== 'cash'" class="grid gap-2">
                            <Label for="ref">Reference</Label>
                            <Input id="ref" v-model="form.reference_no" placeholder="Optional" class="font-mono" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="settling = null">Cancel</Button>
                        <Button type="submit" class="press" :disabled="form.processing">Record</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
