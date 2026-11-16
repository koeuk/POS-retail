<script setup lang="ts">
import BreakdownBars from '@/components/charts/BreakdownBars.vue';
import SalesBarChart from '@/components/charts/SalesBarChart.vue';
import StatTile from '@/components/charts/StatTile.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Download, HandCoins, Receipt, TrendingUp, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface CustomerRow {
    id: number;
    name: string;
    phone: string | null;
    orders_count: number;
    total_spend: string;
}

interface DebtCustomerRow {
    id: number;
    name: string;
    phone: string | null;
    debts_count: number;
    total_owed: string;
}

interface DebtorRow {
    id: number;
    uuid: string;
    name: string;
    phone: string | null;
    unpaid_orders_count: number;
    total_owed: string;
    total_amount: string;
    total_paid: string;
    latest_order_at: string | null;
}

const props = defineProps<{
    filters: { from: string; to: string };
    totals: { orders: number; sales: string; items: number; basket: string };
    byDay: { day: string; orders: number; sales: string }[];
    byProduct: { product_name: string; qty: number; revenue: string }[];
    byPayment: { method: string; count: number; amount: string }[];
    byCustomer: CustomerRow[];
    debtsByCustomer: DebtCustomerRow[];
    debtorsList: DebtorRow[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);

/** Filters sit in one row above the charts and reload on change. */
watch([from, to], () => {
    router.get(route('reports.index'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true, replace: true });
});

function preset(days: number) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));

    from.value = start.toISOString().slice(0, 10);
    to.value = end.toISOString().slice(0, 10);
}

const { money } = useCurrency();

/*
 * Fixed slot per tender type, so cash is always slot 0 no matter how the
 * takings rank on any given day. `capitalize` would render "qr" as "Qr",
 * which is wrong for an initialism.
 */
const METHOD_SLOT: Record<string, number> = { cash: 0, card: 1, qr: 2, credit: 3 };
const METHOD_LABEL: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    qr: 'QR',
    credit: 'Credit',
};

const paymentRows = computed(() =>
    props.byPayment.map((row) => ({
        label: METHOD_LABEL[row.method] ?? row.method,
        value: Number(row.amount),
        meta: `${row.count}×`,
        slot: METHOD_SLOT[row.method] ?? 0,
    })),
);

const productRows = computed(() =>
    props.byProduct.slice(0, 8).map((row) => ({
        label: row.product_name,
        value: Number(row.revenue),
        meta: `${row.qty}`,
    })),
);

const topCustomers = computed(() =>
    props.byCustomer.slice(0, 8).map((row) => ({
        label: row.name,
        value: Number(row.total_spend),
        meta: `${row.phone ? row.phone + ' · ' : ''}${row.orders_count} order${row.orders_count === 1 ? '' : 's'}`,
    })),
);

const debtCustomers = computed(() =>
    props.debtsByCustomer.slice(0, 8).map((row) => ({
        label: row.name,
        value: Number(row.total_owed),
        meta: `${row.phone ? row.phone + ' · ' : ''}${row.debts_count} unpaid`,
    })),
);

const totalDebtOwed = computed(() => props.debtorsList.reduce((sum, d) => sum + Number(d.total_owed), 0));

const exportUrl = computed(() => route('reports.export', { from: from.value, to: to.value }));
</script>

<template>
    <Head title="Reports" />

    <AppLayout :breadcrumbs="[{ title: 'Reports', href: '/reports' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Reports" description="Bucketed by the day the sale actually happened, not the day it reached the server.">
                <template #actions>
                    <Button as-child variant="outline" class="press">
                        <a :href="exportUrl">
                            <Download class="size-4" />
                            CSV
                        </a>
                    </Button>
                </template>
            </PageHeader>

            <!-- Filters: one row, above the charts. -->
            <div class="animate-rise mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-3">
                <DateRangePicker v-model:from="from" v-model:to="to" placeholder="Pick a period" class="w-full sm:w-[16rem]" />

                <div class="ml-auto flex gap-1">
                    <button
                        v-for="p in [
                            { label: '7d', days: 7 },
                            { label: '30d', days: 30 },
                            { label: '90d', days: 90 },
                        ]"
                        :key="p.label"
                        type="button"
                        class="press h-9 rounded-md border border-border px-3 text-xs font-medium text-muted-foreground"
                        @click="preset(p.days)"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!-- Two-up on a phone: four figures in two rows, not a tower. -->
            <div class="stagger grid grid-cols-2 gap-2 sm:gap-4 xl:grid-cols-4">
                <StatTile label="Sales" :value="money(totals.sales)" :icon="TrendingUp" />
                <StatTile label="Orders" :value="String(totals.orders)" :icon="Receipt" />
                <StatTile label="Average basket" :value="money(totals.basket)" :icon="Boxes" />
                <StatTile label="Items sold" :value="String(totals.items)" :icon="Boxes" />
            </div>

            <section class="animate-rise mt-4 rounded-xl border border-border bg-card p-4" style="animation-delay: 120ms">
                <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Sales by day</h2>
                <SalesBarChart :rows="byDay" :height="240" />
            </section>

            <!-- Breakdown: Payment methods & Top products -->
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 160ms">
                    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Payment methods</h2>
                    <!-- Categorical: each row is a different tender type. -->
                    <BreakdownBars :rows="paymentRows" categorical />
                </section>

                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 200ms">
                    <h2 class="mb-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Top products by revenue</h2>
                    <!-- One measure across many products: a single hue, ranked. -->
                    <BreakdownBars :rows="productRows" />
                </section>
            </div>

            <!-- Customer Breakdown: Bought Most & Who in Debt -->
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 240ms">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            <Users class="size-4 text-primary" />
                            Customers who bought the most
                        </h2>
                        <span class="text-xs text-muted-foreground">By total spend</span>
                    </div>
                    <BreakdownBars :rows="topCustomers" show-rank />
                </section>

                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 280ms">
                    <div class="mb-3 flex items-center justify-between">
                        <h2
                            class="flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400"
                        >
                            <HandCoins class="size-4" />
                            Top debt by customer
                        </h2>
                        <span class="text-xs text-muted-foreground">Amount still owed</span>
                    </div>
                    <BreakdownBars :rows="debtCustomers" show-rank tone="warning" />
                </section>
            </div>

            <!-- The table view: Sales by product -->
            <section class="animate-rise mt-4 overflow-hidden rounded-xl border border-border bg-card" style="animation-delay: 320ms">
                <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Sales by product
                </h2>

                <div v-if="byProduct.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Product</TableHead>
                                <TableHead data-numeric class="text-right">Qty</TableHead>
                                <TableHead data-numeric class="text-right">Revenue</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="row in byProduct" :key="row.product_name">
                                <TableCell class="font-medium">{{ row.product_name }}</TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">
                                    {{ row.qty }}
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono font-medium">
                                    {{ money(row.revenue) }}
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>
                <p v-else class="px-4 py-10 text-center text-sm text-muted-foreground">No sales in this range.</p>
            </section>

            <!-- Table: Customers still in debt -->
            <section class="animate-rise mt-4 overflow-hidden rounded-xl border border-border bg-card" style="animation-delay: 360ms">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <div>
                        <h2 class="flex items-center gap-2 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            <HandCoins class="size-4 text-amber-600 dark:text-amber-400" />
                            Customers still in debt
                        </h2>
                        <p class="text-xs text-muted-foreground">Accounts receivable with outstanding balances waiting to be collected.</p>
                    </div>
                    <div v-if="debtorsList.length" class="flex items-center gap-2">
                        <span
                            class="rounded-full border border-amber-500/40 bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400"
                        >
                            {{ debtorsList.length }} customer{{ debtorsList.length === 1 ? '' : 's' }} · {{ money(totalDebtOwed) }} owed
                        </span>
                        <Button as-child variant="outline" size="sm" class="press h-7 text-xs">
                            <Link :href="route('debts.index')">Open Debt book</Link>
                        </Button>
                    </div>
                </div>

                <div v-if="debtorsList.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Customer</TableHead>
                                <TableHead data-numeric class="text-right">Unpaid sales</TableHead>
                                <TableHead data-numeric class="text-right">Total credit</TableHead>
                                <TableHead data-numeric class="text-right">Paid so far</TableHead>
                                <TableHead data-numeric class="text-right">Still owed</TableHead>
                                <TableHead class="text-right">Latest sale</TableHead>
                                <TableHead class="text-right">Action</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="d in debtorsList" :key="d.id" class="group">
                                <TableCell>
                                    <div class="font-medium text-foreground">{{ d.name }}</div>
                                    <div v-if="d.phone" class="font-mono text-xs text-muted-foreground">{{ d.phone }}</div>
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono">
                                    {{ d.unpaid_orders_count }}
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono text-muted-foreground">
                                    {{ money(d.total_amount) }}
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono text-muted-foreground">
                                    {{ money(d.total_paid) }}
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono font-bold text-amber-600 dark:text-amber-400">
                                    {{ money(d.total_owed) }}
                                </TableCell>
                                <TableCell class="text-right text-xs text-muted-foreground">
                                    {{ d.latest_order_at ?? '—' }}
                                </TableCell>
                                <TableCell class="text-right">
                                    <Button as-child variant="ghost" size="sm" class="press h-8 px-2 text-xs text-primary hover:bg-primary/10">
                                        <Link :href="route('debts.index', { 'filter[search]': d.name })"> Settle debt </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>
                <div v-else class="flex flex-col items-center justify-center p-8 text-center">
                    <p class="text-sm font-medium text-foreground">No customers in debt</p>
                    <p class="text-xs text-muted-foreground">All customer sales on credit and pay later orders are paid up.</p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
