<script setup lang="ts">
import BreakdownBars from '@/components/charts/BreakdownBars.vue';
import HourlyBarChart, { type HourlyRow } from '@/components/charts/HourlyBarChart.vue';
import StatTile from '@/components/charts/StatTile.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { Boxes, CircleDollarSign, Clock, CreditCard, Percent, PieChart, ShoppingBag, TrendingUp, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface OverviewData {
    orders: number;
    items: number;
    revenue: string;
    cost: string;
    profit: string;
    margin_percent: number;
    avg_basket: string;
}

interface DayData {
    day: string;
    orders: number;
    sales: string;
}

interface DowData {
    day_index: number;
    name: string;
    short_name: string;
    orders: number;
    sales: string;
}

interface CategoryData {
    name: string;
    orders: number;
    qty: number;
    revenue: string;
}

interface PaymentData {
    method: string;
    count: number;
    amount: string;
}

interface CustomerInsightsData {
    total_orders: number;
    named_orders: number;
    walkin_orders: number;
    unique_customers: number;
    named_revenue: string;
    walkin_revenue: string;
    named_pct: number;
}

interface ProductProfitabilityData {
    product_name: string;
    qty: number;
    revenue: string;
    cost: string;
    profit: string;
    margin_percent: number;
}

const props = defineProps<{
    filters: { from: string; to: string };
    overview: OverviewData;
    byDay: DayData[];
    byHour: HourlyRow[];
    byDayOfWeek: DowData[];
    byCategory: CategoryData[];
    byPayment: PaymentData[];
    customerInsights: CustomerInsightsData;
    productProfitability: ProductProfitabilityData[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const dowMetric = ref<'sales' | 'orders'>('sales');

watch([from, to], () => {
    router.get(route('analyze.index'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true, replace: true });
});

function preset(days: number) {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));

    from.value = start.toISOString().slice(0, 10);
    to.value = end.toISOString().slice(0, 10);
}

const { money, currency } = useCurrency();

// Payment labels and ordering
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

// Category breakdown rows
const categoryRows = computed(() =>
    props.byCategory.map((cat, i) => ({
        label: cat.name,
        value: Number(cat.revenue),
        meta: `${cat.qty} sold`,
        slot: i,
    })),
);

// Day of week analysis
const peakDow = computed(() => {
    if (!props.byDayOfWeek.length) return null;
    let best = props.byDayOfWeek[0];
    let max = dowMetric.value === 'sales' ? Number(best.sales) : best.orders;

    for (const d of props.byDayOfWeek) {
        const val = dowMetric.value === 'sales' ? Number(d.sales) : d.orders;
        if (val > max) {
            max = val;
            best = d;
        }
    }
    return max > 0 ? best : null;
});

const peakDowVal = computed(() => {
    return Math.max(1, ...props.byDayOfWeek.map((d) => (dowMetric.value === 'sales' ? Number(d.sales) : d.orders)));
});

function dowBarWidth(d: DowData): string {
    const val = dowMetric.value === 'sales' ? Number(d.sales) : d.orders;
    return `${Math.max(2, (val / peakDowVal.value) * 100)}%`;
}
</script>

<template>
    <Head title="Analyze" />

    <AppLayout :breadcrumbs="[{ title: 'Analyze', href: '/analyze' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader title="Analyze" description="Business performance trends, peak trading hours, profit margins, and sales intelligence." />

            <!-- Filter Bar -->
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
                        class="press h-9 rounded-md border border-border px-3 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                        @click="preset(p.days)"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!-- KPI Tiles: 4-up -->
            <div class="stagger grid grid-cols-2 gap-2 sm:gap-4 xl:grid-cols-4">
                <StatTile label="Gross Revenue" :value="money(overview.revenue)" :meta="`${overview.orders} orders`" :icon="TrendingUp" />
                <StatTile
                    label="Estimated Gross Profit"
                    :value="money(overview.profit)"
                    :meta="`${overview.margin_percent}% margin`"
                    :icon="CircleDollarSign"
                />
                <StatTile label="Profit Margin" :value="`${overview.margin_percent}%`" :meta="`Cost: ${money(overview.cost)}`" :icon="Percent" />
                <StatTile
                    label="Average Basket (AOV)"
                    :value="money(overview.avg_basket)"
                    :meta="`${overview.items} items sold`"
                    :icon="ShoppingBag"
                />
            </div>

            <!-- Peak Trading Hours (24-Hour Distribution) -->
            <section class="animate-rise mt-4 rounded-xl border border-border bg-card p-4" style="animation-delay: 100ms">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Clock class="size-4 text-primary" />
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-foreground">Peak Trading Hours</h2>
                    </div>
                </div>

                <HourlyBarChart :rows="byHour" :currency="currency" />
            </section>

            <!-- Day of Week & Category Breakdown -->
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Day of Week Performance -->
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 150ms">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <Calendar class="size-4 text-primary" />
                            <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-foreground">Trading by Day of Week</h2>
                        </div>

                        <!-- Metric Toggle -->
                        <div class="flex items-center rounded-lg border border-border bg-muted/40 p-0.5">
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                                :class="dowMetric === 'sales' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                @click="dowMetric = 'sales'"
                            >
                                Revenue
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                                :class="dowMetric === 'orders' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                @click="dowMetric = 'orders'"
                            >
                                Orders
                            </button>
                        </div>
                    </div>

                    <div v-if="peakDow" class="mb-3">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                            <span class="size-1.5 rounded-full bg-primary" />
                            Best day: {{ peakDow.name }} ({{ peakDow.orders }} orders · {{ money(peakDow.sales) }})
                        </span>
                    </div>

                    <!-- Day of Week Bars -->
                    <ul class="space-y-2.5 pt-1">
                        <li v-for="d in byDayOfWeek" :key="d.name" class="space-y-1">
                            <div class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-foreground">{{ d.name }}</span>
                                <span class="flex items-baseline gap-2">
                                    <span class="font-mono text-xs text-muted-foreground">{{ d.orders }} orders</span>
                                    <span class="font-mono font-medium text-foreground">{{ money(d.sales) }}</span>
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted/40">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="d === peakDow ? 'bg-primary' : 'bg-primary/50'"
                                    :style="{ width: dowBarWidth(d) }"
                                />
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Category Sales Breakdown -->
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 200ms">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <PieChart class="size-4 text-primary" />
                            <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-foreground">Category Contribution</h2>
                        </div>
                    </div>

                    <BreakdownBars v-if="categoryRows.length" :rows="categoryRows" :currency="currency" :show-rank="true" />
                    <div v-else class="py-12 text-center text-sm text-muted-foreground">No category sales recorded in this period.</div>
                </section>
            </div>

            <!-- Customer Loyalty & Payment Tender Mix -->
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Customer Dynamics -->
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 250ms">
                    <div class="mb-3 flex items-center gap-2">
                        <Users class="size-4 text-primary" />
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-foreground">Customer Mix</h2>
                    </div>

                    <div class="space-y-4">
                        <!-- Summary Stats -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-lg border border-border/80 bg-muted/20 p-3">
                                <div class="text-xs text-muted-foreground">Unique Customers</div>
                                <div class="mt-1 font-mono text-xl font-semibold text-foreground">
                                    {{ customerInsights.unique_customers }}
                                </div>
                                <div class="mt-0.5 text-[0.6875rem] text-muted-foreground">Registered buyers</div>
                            </div>
                            <div class="rounded-lg border border-border/80 bg-muted/20 p-3">
                                <div class="text-xs text-muted-foreground">Named Customer Share</div>
                                <div class="mt-1 font-mono text-xl font-semibold text-foreground">{{ customerInsights.named_pct }}%</div>
                                <div class="mt-0.5 text-[0.6875rem] text-muted-foreground">Of all orders</div>
                            </div>
                        </div>

                        <!-- Progress Bar of Named vs Walk-in -->
                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs text-muted-foreground">
                                <span>Named: {{ customerInsights.named_orders }} ({{ money(customerInsights.named_revenue) }})</span>
                                <span>Walk-in: {{ customerInsights.walkin_orders }} ({{ money(customerInsights.walkin_revenue) }})</span>
                            </div>
                            <div class="flex h-3 w-full overflow-hidden rounded-full bg-muted/50">
                                <div class="h-full bg-primary transition-all duration-300" :style="{ width: `${customerInsights.named_pct}%` }" />
                                <div
                                    class="h-full bg-muted-foreground/30 transition-all duration-300"
                                    :style="{ width: `${100 - customerInsights.named_pct}%` }"
                                />
                            </div>
                            <div class="flex items-center gap-4 pt-0.5 text-[0.6875rem] text-muted-foreground">
                                <span class="flex items-center gap-1.5">
                                    <span class="size-2 rounded-full bg-primary" />
                                    Named / Registered ({{ customerInsights.named_pct }}%)
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <span class="size-2 rounded-full bg-muted-foreground/40" />
                                    Walk-in ({{ (100 - customerInsights.named_pct).toFixed(1) }}%)
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Payment Tender Mix -->
                <section class="animate-rise rounded-xl border border-border bg-card p-4" style="animation-delay: 300ms">
                    <div class="mb-3 flex items-center gap-2">
                        <CreditCard class="size-4 text-primary" />
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-foreground">Payment Methods</h2>
                    </div>

                    <BreakdownBars v-if="paymentRows.length" :rows="paymentRows" :currency="currency" :categorical="true" />
                    <div v-else class="py-12 text-center text-sm text-muted-foreground">No payment records in this period.</div>
                </section>
            </div>

            <!-- Top Products Profitability & Margins -->
            <section class="animate-rise mt-4 rounded-xl border border-border bg-card p-4" style="animation-delay: 350ms">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Boxes class="size-4 text-primary" />
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-foreground">
                            Top Products Profitability & Margins
                        </h2>
                    </div>
                </div>

                <div v-if="productProfitability.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-12">#</TableHead>
                                <TableHead>Product</TableHead>
                                <TableHead class="text-right">Qty Sold</TableHead>
                                <TableHead class="text-right">Revenue</TableHead>
                                <TableHead class="text-right">Est. Cost</TableHead>
                                <TableHead class="text-right">Est. Profit</TableHead>
                                <TableHead class="text-right">Margin</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody>
                            <TableRow v-for="(p, idx) in productProfitability" :key="p.product_name">
                                <TableCell class="font-mono text-xs text-muted-foreground">{{ idx + 1 }}</TableCell>
                                <TableCell class="font-medium text-foreground">{{ p.product_name }}</TableCell>
                                <TableCell class="text-right font-mono text-sm">{{ p.qty }}</TableCell>
                                <TableCell class="text-right font-mono text-sm font-medium">{{ money(p.revenue) }}</TableCell>
                                <TableCell class="text-right font-mono text-sm text-muted-foreground">{{ money(p.cost) }}</TableCell>
                                <TableCell class="text-right font-mono text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                                    {{ money(p.profit) }}
                                </TableCell>
                                <TableCell class="text-right">
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 font-mono text-xs font-medium"
                                        :class="
                                            p.margin_percent >= 30
                                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                : p.margin_percent >= 15
                                                  ? 'bg-sky-500/10 text-sky-600 dark:text-sky-400'
                                                  : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                        "
                                    >
                                        {{ p.margin_percent }}%
                                    </span>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>
                <div v-else class="py-12 text-center text-sm text-muted-foreground">No product sales in this period.</div>
            </section>
        </div>
    </AppLayout>
</template>
