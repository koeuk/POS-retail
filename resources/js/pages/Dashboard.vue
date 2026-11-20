<script setup lang="ts">
import BreakdownBars from '@/components/charts/BreakdownBars.vue';
import SalesBarChart from '@/components/charts/SalesBarChart.vue';
import StatTile from '@/components/charts/StatTile.vue';
import DatePicker from '@/components/DatePicker.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    ChartNoAxesColumn,
    ChevronRight,
    CloudOff,
    HandCoins,
    PackageSearch,
    Receipt,
    ScanBarcode,
    Shapes,
    TriangleAlert,
    Utensils,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Summary {
    sales: string;
    orders: number;
    basket: string;
    items: number;
}

interface StockRow {
    id: number;
    qty: number;
    low_stock_threshold: number | null;
    product: { id: number; name: string; unit: string } | null;
    store: { id: number; name: string } | null;
}

interface Spent {
    count: number;
    value: string;
}

interface ProductSale {
    product_name: string;
    qty: number;
    revenue: string;
}

interface TopProducts {
    today: ProductSale[];
    week: ProductSale[];
    month: ProductSale[];
}

const props = defineProps<{
    today: Summary;
    yesterday: Summary;
    trend: { day: string; orders: number; sales: string }[];
    lowStock: StockRow[];
    oversold: StockRow[];
    recentOrders: {
        id: number;
        order_no: string;
        total: string;
        created_offline_at: string | null;
        cashier: { id: number; name: string } | null;
    }[];
    offlineToday: number;
    debts: { count: number; owed: string };
    myself: { week: Spent; month: Spent; year: Spent };
    topProducts: TopProducts;
    catalogue: { products: number; categories: number };
    canSeeReports: boolean;
    filters: { date: string; isToday: boolean };
}>();

/*
 * The dashboard answers "how was the day?" — this picks which day. Today by
 * default; any past day reloads the same screen for that date.
 */
const date = ref(props.filters.date);
const maxDate = new Date().toISOString().slice(0, 10);

watch(date, (value) => {
    if (!value) return;
    router.get(route('dashboard'), value === maxDate ? {} : { date: value }, { preserveState: true, preserveScroll: true, replace: true });
});

const heroLabel = computed(() =>
    props.filters.isToday ? "Today's sales" : `Sales · ${new Date(props.filters.date).toLocaleDateString(undefined, { dateStyle: 'medium' })}`,
);

const times = (n: number) => `${n} time${n === 1 ? '' : 's'}, at shelf price`;

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

/** "Good morning" / "Good afternoon" / "Good evening", by the clock. */
const greeting = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
});

const { money, currency } = useCurrency();

const topPeriod = ref<'today' | 'week' | 'month'>(props.topProducts?.today?.length ? 'today' : 'week');
const topMetric = ref<'qty' | 'revenue'>('qty');

const topProductRows = computed(() => {
    const list = props.topProducts?.[topPeriod.value] ?? [];
    if (!list.length) return [];

    const sorted = [...list].sort((a, b) => {
        if (topMetric.value === 'qty') {
            return b.qty - a.qty;
        }
        return Number(b.revenue) - Number(a.revenue);
    });

    return sorted.slice(0, 5).map((p) => {
        if (topMetric.value === 'qty') {
            return {
                label: p.product_name,
                value: p.qty,
                meta: money(p.revenue),
            };
        }
        return {
            label: p.product_name,
            value: Number(p.revenue),
            meta: `${p.qty} sold`,
        };
    });
});

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '—');

const weekTotal = computed(() => props.trend.reduce((sum, row) => sum + Number(row.sales), 0));

/** Change against yesterday, for the hero chip. Null when there is no base. */
const salesDelta = computed(() => {
    const now = Number(props.today.sales);
    const before = Number(props.yesterday.sales);
    if (!Number.isFinite(before) || before === 0) return null;
    return ((now - before) / before) * 100;
});

/*
 * The four places a shopkeeper actually goes from here. Rendered as a row of
 * round buttons inside the hero on a phone — a thumb reaches them without
 * scrolling, which a list of full-width cards never manages.
 */
const quickActions = computed(() =>
    [
        { title: 'Sell', href: '/pos', icon: ScanBarcode, chip: 'bg-chart-1/10 text-chart-1', always: true },
        { title: 'Orders', href: '/orders', icon: Receipt, chip: 'bg-chart-2/15 text-chart-2', always: props.canSeeReports },
        { title: 'Stock', href: '/inventory', icon: PackageSearch, chip: 'bg-chart-5/10 text-chart-5', always: props.canSeeReports },
        { title: 'Reports', href: '/reports', icon: ChartNoAxesColumn, chip: 'bg-chart-4/15 text-chart-4', always: props.canSeeReports },
    ].filter((a) => a.always),
);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="[{ title: 'Dashboard', href: '/dashboard' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader description="Today across the shop floor.">
                <!-- Soft hello, bold name: the greeting is furniture, the name is the title. -->
                <template #title>
                    <span class="font-normal text-muted-foreground">{{ greeting }},</span>
                    {{ user?.name ?? 'Dashboard' }}
                </template>
                <template #actions>
                    <DatePicker v-model="date" aria-label="Show figures for a day" class="h-10 rounded-full" />
                </template>
            </PageHeader>

            <!-- Phone: the shortcuts live in their own card under the figure on mobile,
                 so mobile screens still have direct thumb access. -->
            <nav class="animate-rise mb-4 flex items-stretch gap-2 lg:hidden" style="animation-delay: 40ms" aria-label="Quick actions">
                <Link
                    v-for="action in quickActions"
                    :key="action.href"
                    :href="action.href"
                    class="press flex flex-1 flex-col items-center gap-1.5 rounded-2xl border border-border bg-card px-2 py-3"
                >
                    <span class="flex size-11 items-center justify-center rounded-full" :class="action.chip">
                        <component :is="action.icon" class="size-5" />
                    </span>
                    <span class="text-[0.7rem] font-medium text-muted-foreground">{{ action.title }}</span>
                </Link>
            </nav>

            <!-- Phone summary: one swipeable rail, bleeding to the screen
                 edges — the half-visible card is the scroll affordance.
                 Desktop shows these figures in the wider tile rows below. -->
            <div v-if="canSeeReports" class="stagger scrollbar-none mb-4 flex snap-x snap-proximity gap-2 overflow-x-auto rounded-2xl lg:hidden">
                <StatTile class="w-44 shrink-0 snap-start" label="Products" :value="String(catalogue.products)" :icon="Boxes" :accent="1" />
                <StatTile class="w-44 shrink-0 snap-start" label="Categories" :value="String(catalogue.categories)" :icon="Shapes" :accent="2" />
                <StatTile
                    class="w-44 shrink-0 snap-start"
                    label="In debt"
                    :value="money(debts.owed)"
                    :icon="HandCoins"
                    :tone="debts.count > 0 ? 'warning' : 'default'"
                    :hint="`${debts.count} sale${debts.count === 1 ? '' : 's'} on credit`"
                />
                <StatTile
                    class="w-44 shrink-0 snap-start"
                    label="Myself · month"
                    :value="money(myself.month.value)"
                    :icon="Utensils"
                    :accent="4"
                    :hint="times(myself.month.count)"
                />
            </div>

            <!-- Two-column dashboard layout (Left 2/3, Right 1/3) -->
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                <!-- LEFT COLUMN (2/3 width) -->
                <div class="space-y-4 lg:col-span-2">
                    <!-- Row 1: Today's Sales, Orders, Average basket & Synced from offline in ONE row -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <!-- Today's Sales Hero Tile -->
                        <article
                            class="animate-rise relative flex flex-col justify-between overflow-hidden rounded-2xl bg-brand p-4 text-brand-foreground shadow-sm"
                        >
                            <div class="relative flex items-start justify-between gap-1">
                                <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] opacity-85">
                                    {{ heroLabel }}
                                </p>
                                <span
                                    v-if="salesDelta !== null"
                                    class="rounded-full px-1.5 py-0.5 text-[0.65rem] font-semibold"
                                    :class="salesDelta >= 0 ? 'bg-white/20 text-white' : 'bg-white/10 text-white/80'"
                                >
                                    {{ salesDelta >= 0 ? '+' : '' }}{{ salesDelta.toFixed(0) }}%
                                </span>
                            </div>

                            <p class="tabular relative mt-1 font-mono text-2xl font-bold leading-none">
                                {{ money(today.sales) }}
                            </p>

                            <p class="tabular relative mt-1.5 truncate text-xs opacity-85">
                                {{ today.orders }} order{{ today.orders === 1 ? '' : 's' }} · {{ today.items }} item{{ today.items === 1 ? '' : 's' }}
                                <span v-if="offlineToday > 0"> · {{ offlineToday }} synced</span>
                            </p>
                        </article>

                        <StatTile label="Orders" :value="String(today.orders)" :icon="Receipt" :accent="2" :previous="yesterday.orders" />
                        <StatTile
                            label="Average basket"
                            :value="money(today.basket)"
                            :icon="Boxes"
                            :accent="1"
                            :hint="`${today.items} item${today.items === 1 ? '' : 's'} sold`"
                        />
                        <StatTile
                            label="Synced from offline"
                            :value="String(offlineToday)"
                            :icon="CloudOff"
                            :accent="5"
                            hint="Sales rung up without a connection"
                        />
                    </div>

                    <!-- Owner's stats (Owed to you, Myself week, Myself month, Myself year) -->
                    <div v-if="canSeeReports" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatTile
                            label="Owed to you"
                            :value="money(debts.owed)"
                            :icon="HandCoins"
                            :tone="debts.count > 0 ? 'warning' : 'default'"
                            :hint="`${debts.count} sale${debts.count === 1 ? '' : 's'} on credit, not yet paid`"
                        />
                        <StatTile
                            label="Myself · week"
                            :value="money(myself.week.value)"
                            :icon="Utensils"
                            :accent="4"
                            :hint="times(myself.week.count)"
                        />
                        <StatTile
                            label="Myself · month"
                            :value="money(myself.month.value)"
                            :icon="Utensils"
                            :accent="4"
                            :hint="times(myself.month.count)"
                        />
                        <StatTile
                            label="Myself · year"
                            :value="money(myself.year.value)"
                            :icon="Utensils"
                            :accent="4"
                            :hint="times(myself.year.count)"
                        />
                    </div>

                    <!-- Sales · last 7 days chart -->
                    <section class="animate-rise min-w-0 rounded-2xl border border-border bg-card p-4" style="animation-delay: 120ms">
                        <div class="mb-3 flex items-baseline justify-between">
                            <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Sales · last 7 days</h2>
                            <p class="tabular font-mono text-sm font-semibold">{{ money(weekTotal) }}</p>
                        </div>

                        <SalesBarChart :rows="trend" :height="200" />
                    </section>

                    <!--
                        Oversold is the reconciliation list: stock driven below zero
                        by offline sales that synced after the shelf was empty. It is
                        the deliberate cost of never rejecting a completed sale.
                    -->
                    <section
                        v-if="oversold.length"
                        class="animate-rise rounded-2xl border border-destructive/40 bg-card"
                        style="animation-delay: 200ms"
                    >
                        <h2
                            class="flex items-center gap-2 border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-destructive"
                        >
                            <TriangleAlert class="size-4" />
                            Oversold — needs reconciling
                        </h2>
                        <ul class="divide-y divide-border">
                            <li v-for="row in oversold" :key="row.id" class="flex items-center gap-3 px-4 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ row.product?.name }}</p>
                                    <p class="truncate text-[0.7rem] text-muted-foreground">{{ row.store?.name }}</p>
                                </div>
                                <span class="tabular font-mono text-sm font-semibold text-destructive"> {{ row.qty }} {{ row.product?.unit }} </span>
                            </li>
                        </ul>
                    </section>

                    <!-- Low stock -->
                    <section class="animate-rise rounded-2xl border border-border bg-card" style="animation-delay: 240ms">
                        <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                            Low stock
                        </h2>
                        <ul v-if="lowStock.length" class="divide-y divide-border">
                            <li v-for="row in lowStock" :key="row.id" class="flex items-center gap-3 px-4 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">{{ row.product?.name }}</p>
                                    <p class="truncate text-[0.7rem] text-muted-foreground">{{ row.store?.name }}</p>
                                </div>
                                <Badge variant="outline" class="tabular font-mono"> {{ row.qty }} / {{ row.low_stock_threshold }} </Badge>
                            </li>
                        </ul>
                        <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Everything is above its threshold.</p>
                    </section>
                </div>

                <!-- RIGHT COLUMN (1/3 width) -->
                <div class="space-y-4 lg:col-span-1">
                    <!-- Quick Actions Banner (matching green style & height of Row 1) -->
                    <section
                        class="animate-rise hidden min-h-[105px] items-center justify-around rounded-2xl bg-brand p-3.5 text-brand-foreground shadow-sm lg:flex"
                    >
                        <Link v-for="action in quickActions" :key="action.href" :href="action.href" class="press flex flex-col items-center gap-1">
                            <span class="flex size-10 items-center justify-center rounded-full bg-white/15 transition-colors hover:bg-white/25">
                                <component :is="action.icon" class="size-5" />
                            </span>
                            <span class="text-[0.6875rem] font-medium opacity-80">{{ action.title }}</span>
                        </Link>
                    </section>

                    <!-- Top selling products chart -->
                    <section class="animate-rise rounded-2xl border border-border bg-card p-4" style="animation-delay: 140ms">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                            <div class="flex items-center gap-1.5">
                                <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Top selling products</h2>
                                <Link
                                    v-if="canSeeReports"
                                    :href="route('reports.index')"
                                    class="press flex items-center text-xs font-medium text-primary hover:underline"
                                    title="View full reports"
                                >
                                    <ChevronRight class="size-3.5" />
                                </Link>
                            </div>

                            <div class="flex items-center gap-1.5">
                                <!-- Period Selector -->
                                <div class="flex items-center rounded-lg bg-muted p-0.5 text-[0.7rem]" role="group" aria-label="Select period">
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-0.5 font-medium transition-colors"
                                        :class="
                                            topPeriod === 'today'
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="topPeriod = 'today'"
                                    >
                                        {{ filters.isToday ? 'Today' : 'Day' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-0.5 font-medium transition-colors"
                                        :class="
                                            topPeriod === 'week'
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="topPeriod = 'week'"
                                    >
                                        7d
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-0.5 font-medium transition-colors"
                                        :class="
                                            topPeriod === 'month'
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="topPeriod = 'month'"
                                    >
                                        30d
                                    </button>
                                </div>

                                <!-- Metric Toggle: Qty / Revenue -->
                                <div class="flex items-center rounded-lg bg-muted p-0.5 text-[0.7rem]" role="group" aria-label="Sort by metric">
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-0.5 font-medium transition-colors"
                                        :class="
                                            topMetric === 'qty'
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        title="Top products by units sold"
                                        @click="topMetric = 'qty'"
                                    >
                                        Qty
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md px-2 py-0.5 font-medium transition-colors"
                                        :class="
                                            topMetric === 'revenue'
                                                ? 'bg-background text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        title="Top products by revenue"
                                        @click="topMetric = 'revenue'"
                                    >
                                        Rev
                                    </button>
                                </div>
                            </div>
                        </div>

                        <BreakdownBars
                            :rows="topProductRows"
                            :currency="currency"
                            :format-value="topMetric === 'qty' ? (n) => `${n} sold` : undefined"
                            show-rank
                        />
                    </section>

                    <!-- Latest sales -->
                    <section class="animate-rise rounded-2xl border border-border bg-card" style="animation-delay: 160ms">
                        <div class="flex items-center justify-between border-b border-border px-4 py-3">
                            <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Latest sales</h2>
                            <Link
                                v-if="canSeeReports"
                                :href="route('orders.index')"
                                class="press flex items-center gap-0.5 text-xs font-medium text-primary"
                            >
                                View all
                                <ChevronRight class="size-3.5" />
                            </Link>
                        </div>

                        <ul v-if="recentOrders.length" class="divide-y divide-border">
                            <li v-for="order in recentOrders" :key="order.id" class="flex items-center gap-3 px-4 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="tabular truncate font-mono text-xs font-medium">{{ order.order_no }}</p>
                                    <p class="truncate text-[0.7rem] text-muted-foreground">
                                        {{ order.cashier?.name ?? 'Unknown' }} · {{ time(order.created_offline_at) }}
                                    </p>
                                </div>
                                <Money :value="order.total" :muted="false" />
                            </li>
                        </ul>
                        <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">No sales yet today.</p>
                    </section>
                </div>
            </div>

            <div v-if="canSeeReports" class="mt-4 flex justify-center">
                <Button as-child variant="outline" class="press">
                    <Link :href="route('reports.index')">Full reports</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
