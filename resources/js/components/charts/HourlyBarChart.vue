<script setup lang="ts">
import { formatCurrency, USD, type CurrencyDef } from '@/composables/useCurrency';
import { computed, ref } from 'vue';

export interface HourlyRow {
    hour: number;
    label: string;
    orders: number;
    sales: string;
}

const props = withDefaults(
    defineProps<{
        rows: HourlyRow[];
        currency?: CurrencyDef;
    }>(),
    { currency: () => USD },
);

const metric = ref<'sales' | 'orders'>('orders');
const hovered = ref<number | null>(null);

const values = computed(() => props.rows.map((r) => (metric.value === 'sales' ? Number(r.sales) : r.orders)));

const peak = computed(() => Math.max(1, ...values.value));

const peakHour = computed(() => {
    let maxVal = -1;
    let maxIndex = 0;
    values.value.forEach((v, idx) => {
        if (v > maxVal) {
            maxVal = v;
            maxIndex = idx;
        }
    });
    return maxVal > 0 ? props.rows[maxIndex] : null;
});

// Calculate 4 time periods: Morning (6-11), Afternoon (12-16), Evening (17-21), Night (22-5)
const periodBreakdown = computed(() => {
    const periods = {
        morning: { label: 'Morning (6am - 12pm)', orders: 0, sales: 0 },
        afternoon: { label: 'Afternoon (12pm - 5pm)', orders: 0, sales: 0 },
        evening: { label: 'Evening (5pm - 10pm)', orders: 0, sales: 0 },
        night: { label: 'Night (10pm - 6am)', orders: 0, sales: 0 },
    };

    props.rows.forEach((r) => {
        const h = r.hour;
        const s = Number(r.sales);
        const o = r.orders;

        if (h >= 6 && h < 12) {
            periods.morning.orders += o;
            periods.morning.sales += s;
        } else if (h >= 12 && h < 17) {
            periods.afternoon.orders += o;
            periods.afternoon.sales += s;
        } else if (h >= 17 && h < 22) {
            periods.evening.orders += o;
            periods.evening.sales += s;
        } else {
            periods.night.orders += o;
            periods.night.sales += s;
        }
    });

    return periods;
});

function money(amount: number): string {
    return formatCurrency(amount, props.currency);
}

function formatHour(h: number): string {
    if (h === 0) return '12a';
    if (h === 12) return '12p';
    return h > 12 ? `${h - 12}p` : `${h}a`;
}
</script>

<template>
    <div class="space-y-4">
        <!-- Header with toggle and peak indicator -->
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span
                    v-if="peakHour"
                    class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary"
                >
                    <span class="size-1.5 animate-pulse rounded-full bg-primary" />
                    Busiest: {{ peakHour.label }} ({{ peakHour.orders }} orders · {{ money(Number(peakHour.sales)) }})
                </span>
                <span v-else class="text-xs text-muted-foreground">No traffic recorded in this period</span>
            </div>

            <!-- View toggle -->
            <div class="flex items-center rounded-lg border border-border bg-muted/40 p-0.5">
                <button
                    type="button"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="metric === 'orders' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="metric = 'orders'"
                >
                    Orders
                </button>
                <button
                    type="button"
                    class="rounded-md px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="metric === 'sales' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="metric = 'sales'"
                >
                    Revenue
                </button>
            </div>
        </div>

        <!-- 24-Hour Bar Chart -->
        <div class="relative h-44 w-full pt-4">
            <div class="flex h-36 items-end gap-1 sm:gap-1.5">
                <div
                    v-for="(row, idx) in rows"
                    :key="row.hour"
                    class="group relative flex h-full flex-1 flex-col items-center justify-end"
                    @mouseenter="hovered = idx"
                    @mouseleave="hovered = null"
                >
                    <!-- Tooltip -->
                    <div
                        v-if="hovered === idx"
                        class="pointer-events-none absolute -top-12 z-20 whitespace-nowrap rounded-md border border-border bg-popover px-2 py-1 text-xs text-popover-foreground shadow-md transition-all"
                    >
                        <div class="font-semibold">{{ row.label }} - {{ String(row.hour).padStart(2, '0') }}:59</div>
                        <div class="text-[0.6875rem] text-muted-foreground">{{ row.orders }} orders · {{ money(Number(row.sales)) }}</div>
                    </div>

                    <!-- Bar -->
                    <div
                        class="w-full rounded-t transition-all duration-200"
                        :class="[
                            values[idx] === peak && peak > 0
                                ? 'bg-primary'
                                : values[idx] > 0
                                  ? 'bg-primary/50 group-hover:bg-primary/80'
                                  : 'bg-muted/40',
                        ]"
                        :style="{
                            height: `${Math.max(4, (values[idx] / peak) * 100)}%`,
                        }"
                    />

                    <!-- Hour label (every 3 hours to prevent crowding) -->
                    <span
                        class="mt-1.5 select-none font-mono text-[0.625rem] text-muted-foreground"
                        :class="{ 'font-semibold text-foreground': row.hour % 3 === 0 || hovered === idx }"
                    >
                        {{ row.hour % 3 === 0 ? formatHour(row.hour) : '' }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Time of Day Summary Badges -->
        <div class="grid grid-cols-2 gap-2 pt-2 sm:grid-cols-4">
            <div
                v-for="period in [periodBreakdown.morning, periodBreakdown.afternoon, periodBreakdown.evening, periodBreakdown.night]"
                :key="period.label"
                class="rounded-lg border border-border/70 bg-muted/20 p-2 text-xs"
            >
                <div class="text-[0.6875rem] text-muted-foreground">{{ period.label }}</div>
                <div class="mt-0.5 flex items-baseline justify-between font-medium">
                    <span>{{ period.orders }} orders</span>
                    <span class="font-mono text-[0.6875rem] text-muted-foreground">{{ money(period.sales) }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
