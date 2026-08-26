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
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { HandCoins, Search } from 'lucide-vue-next';
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
                                    <Button v-if="owed(d) > 0" size="sm" class="press" @click="openSettle(d)">Record payment</Button>
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
