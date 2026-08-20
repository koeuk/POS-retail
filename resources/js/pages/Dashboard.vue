<script setup lang="ts">
import SalesBarChart from '@/components/charts/SalesBarChart.vue';
import StatTile from '@/components/charts/StatTile.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Boxes, CloudOff, Receipt, ScanBarcode, TrendingUp, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

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
    canSeeReports: boolean;
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
});

const money = (v: string | number) => `$${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '—');

const weekTotal = computed(() => props.trend.reduce((sum, row) => sum + Number(row.sales), 0));
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="[{ title: 'Dashboard', href: '/dashboard' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader :eyebrow="greeting" :title="user?.name ?? 'Dashboard'" description="Today across the shop floor.">
                <template #actions>
                    <Button as-child class="press">
                        <Link href="/pos">
                            <ScanBarcode class="size-4" />
                            Open POS
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="stagger grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile label="Today's sales" :value="money(today.sales)" :icon="TrendingUp" :previous="yesterday.sales" />
                <StatTile label="Orders" :value="String(today.orders)" :icon="Receipt" :previous="yesterday.orders" />
                <StatTile
                    label="Average basket"
                    :value="money(today.basket)"
                    :icon="Boxes"
                    :hint="`${today.items} item${today.items === 1 ? '' : 's'} sold`"
                />
                <StatTile label="Synced from offline" :value="String(offlineToday)" :icon="CloudOff" hint="Sales rung up without a connection" />
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <!-- One series, so no legend: the panel title names it. -->
                <section class="animate-rise rounded-xl border border-border bg-card p-4 shadow-sm lg:col-span-2" style="animation-delay: 120ms">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Sales · last 7 days</h2>
                        <p class="tabular font-mono text-sm font-semibold">{{ money(weekTotal) }}</p>
                    </div>

                    <SalesBarChart :rows="trend" :height="200" />
                </section>

                <section class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 160ms">
                    <h2 class="border-b border-border px-4 py-3 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Latest sales
                    </h2>

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

            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <!--
                    Oversold is the reconciliation list: stock driven below zero
                    by offline sales that synced after the shelf was empty. It is
                    the deliberate cost of never rejecting a completed sale.
                -->
                <section
                    v-if="oversold.length"
                    class="animate-rise rounded-xl border border-destructive/40 bg-card shadow-sm"
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

                <section class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 240ms">
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

            <div v-if="canSeeReports" class="mt-4 flex justify-center">
                <Button as-child variant="outline" class="press">
                    <Link :href="route('reports.index')">Full reports</Link>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
