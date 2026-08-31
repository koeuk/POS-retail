<script setup lang="ts">
import StatTile from '@/components/charts/StatTile.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, Utensils } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Row {
    id: number;
    order_no: string;
    total: string;
    created_at: string;
    created_offline_at: string | null;
    cashier: { id: number; name: string } | null;
    items: { id: number; product_name: string; qty: number }[];
}

const props = defineProps<{
    rows: Paginated<Row>;
    filters: { search: string; from: string; to: string };
    summary: { month_count: number; month_value: string; month_label: string };
}>();

const { money } = useCurrency();

const search = ref(props.filters.search);
// The picker hands back undefined when a range is cleared; '' from the server means the same thing.
const from = ref<string | undefined>(props.filters.from || undefined);
const to = ref<string | undefined>(props.filters.to || undefined);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('consumption.index'),
        { filter: { search: search.value || undefined, from: from.value || undefined, to: to.value || undefined } },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch([from, to], reload);

const when = (r: Row) => new Date(r.created_offline_at ?? r.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** "Cola 330ml ×2, Bar Soap" — the whole take on one line. */
const summarise = (r: Row) => r.items.map((i) => (i.qty > 1 ? `${i.product_name} ×${i.qty}` : i.product_name)).join(', ');
</script>

<template>
    <Head title="Myself" />

    <AppLayout :breadcrumbs="[{ title: 'Myself', href: '/consumption' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                eyebrow="Selling"
                title="Myself"
                description="Things you took for yourself. Stock goes down but nothing counts as a sale — the value shown is what it would have sold for."
            />

            <div class="stagger mb-4 grid gap-4 sm:grid-cols-2">
                <StatTile :label="`Taken in ${summary.month_label}`" :value="money(summary.month_value)" :icon="Utensils" hint="At shelf price" />
                <StatTile label="Times this month" :value="String(summary.month_count)" :icon="Utensils" />
            </div>

            <div class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 60ms">
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search by product…" class="pl-9" autocomplete="off" />
                    </div>
                    <DateRangePicker v-model:from="from" v-model:to="to" placeholder="Any date" class="w-full sm:w-[16rem]" />
                </div>

                <div v-if="rows.data.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>What</TableHead>
                                <TableHead>When</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead data-numeric class="text-right">Value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="r in rows.data" :key="r.id">
                                <TableCell>
                                    <Link :href="route('orders.show', { order: r.id })" class="block max-w-md truncate font-medium hover:underline">{{
                                        summarise(r)
                                    }}</Link>
                                    <p class="tabular font-mono text-xs text-muted-foreground">{{ r.order_no }}</p>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-sm text-muted-foreground">{{ when(r) }}</TableCell>
                                <TableCell class="text-sm text-muted-foreground">{{ r.cashier?.name ?? '—' }}</TableCell>
                                <TableCell data-numeric class="text-right"><Money :value="r.total" /></TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <EmptyState
                    v-else
                    :icon="Utensils"
                    title="Nothing taken"
                    description="Choose “Myself” at the till when you take something for yourself."
                />

                <Pagination :links="rows.links" :from="rows.from" :to="rows.to" :total="rows.total" :per-page="rows.per_page" />
            </div>
        </div>
    </AppLayout>
</template>
