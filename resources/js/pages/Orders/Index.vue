<script setup lang="ts">
import DateRangePicker from '@/components/DateRangePicker.vue';
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrency } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import { currentPerPage } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Banknote,
    Check,
    Clock,
    CloudOff,
    CreditCard,
    Eye,
    HandCoins,
    Printer,
    QrCode,
    ReceiptText,
    Search,
    Utensils,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface OrderRow {
    id: number;
    uuid: string;
    order_no: string;
    total: string;
    status: string;
    sale_type: 'customer' | 'debt' | 'myself';
    paid_amount: string;
    items_count: number;
    created_at: string;
    created_offline_at: string | null;
    cashier: { id: number; name: string } | null;
    store: { id: number; name: string } | null;
    register: { id: number; name: string } | null;
    customer: { id: number; name: string } | null;
    payments: { id: number; method: string; amount: string }[];
}

const props = defineProps<{
    orders: Paginated<OrderRow>;
    filters: { search?: string; status?: string; method?: string; from?: string; to?: string };
    statuses: { value: string; label: string }[];
    methods: { value: string; label: string }[];
}>();

const ALL = 'all';
const { money } = useCurrency();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? ALL);
const method = ref(props.filters.method ?? ALL);
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('orders.index'),
        {
            filter: {
                search: search.value || undefined,
                status: status.value === ALL ? undefined : status.value,
                method: method.value === ALL ? undefined : method.value,
                from: from.value || undefined,
                to: to.value || undefined,
            },
            per_page: currentPerPage(),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch([status, method, from, to], reload);

/* The moment the sale happened, which for an offline order is not the moment
   the row was created here. */
const soldAt = (row: OrderRow) =>
    new Date(row.created_offline_at ?? row.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const owed = (o: OrderRow) => Math.max(0, Number(o.total) - Number(o.paid_amount));

const statusTone = (s: string) => (s === 'completed' ? 'secondary' : s === 'refunded' ? 'outline' : 'destructive');

const methodLabel = (m: string) => (m === 'qr' ? 'QR' : m.charAt(0).toUpperCase() + m.slice(1));

const payModalOpen = ref(false);
const payingOrder = ref<OrderRow | null>(null);
const payAmount = ref<number>(0);
const payMethod = ref<'cash' | 'card' | 'qr'>('cash');
const payRef = ref('');
const isSubmitting = ref(false);

function openPayModal(order: OrderRow) {
    payingOrder.value = order;
    payAmount.value = owed(order);
    payMethod.value = 'cash';
    payRef.value = '';
    payModalOpen.value = true;
}

function submitPayment() {
    if (!payingOrder.value || payAmount.value <= 0) return;
    isSubmitting.value = true;

    router.post(
        route('orders.pay', payingOrder.value.uuid),
        {
            amount: payAmount.value,
            method: payMethod.value,
            reference_no: payRef.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                payModalOpen.value = false;
                payingOrder.value = null;
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Order History" />

    <AppLayout :breadcrumbs="[{ title: 'Order History', href: '/orders' }]">
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader
                title="Order History"
                description="Every sale on the server, from every till. Sales still queued on a tablet appear once they sync."
            />

            <div class="animate-rise rounded-xl border border-border bg-card" style="animation-delay: 60ms">
                <!-- One row: search flexes, the filters keep their width and
                     wrap under it on screens too narrow to hold all four. -->
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Order no., cashier or customer…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>

                    <Select v-model="status">
                        <SelectTrigger class="h-10 w-auto min-w-[7rem] shrink-0 rounded-full"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All status</SelectItem>
                            <SelectItem v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="method">
                        <SelectTrigger class="h-10 w-auto min-w-[7.5rem] shrink-0 rounded-full"><SelectValue placeholder="Payment" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All payments</SelectItem>
                            <SelectItem v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <DateRangePicker v-model:from="from" v-model:to="to" placeholder="Any date" class="h-10 shrink-0 rounded-full" />
                </div>

                <!-- Phone: one card per sale. A seven-column table has no honest
                     way to fit 390px — it only pretends to, behind a sideways
                     scroll nobody discovers. -->
                <ul v-if="orders.data.length" class="space-y-2 p-2.5 md:hidden">
                    <li
                        v-for="order in orders.data"
                        :key="order.id"
                        class="relative overflow-hidden rounded-xl border border-border bg-card"
                    >
                        <Link :href="route('orders.show', { order: order.uuid })" class="row-press block px-3.5 py-3">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="tabular truncate font-mono text-xs font-semibold">{{ order.order_no }}</span>
                                <Money :value="order.total" :muted="false" class="shrink-0 text-[0.95rem] font-semibold" />
                            </div>

                            <p class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <CloudOff v-if="order.created_offline_at" class="size-3 shrink-0 text-primary" />
                                {{ soldAt(order) }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ order.cashier?.name ?? '—' }}<span v-if="order.register"> · {{ order.register.name }}</span>
                                <span v-if="order.customer"> · {{ order.customer.name }}</span>
                            </p>

                            <div class="mt-2 flex flex-wrap items-center gap-1 pr-9">
                                <Badge :variant="statusTone(order.status)" class="capitalize">{{ order.status }}</Badge>
                                <Badge v-if="owed(order) > 0 && order.sale_type === 'debt'" variant="destructive" class="gap-1">
                                    <HandCoins class="size-3" />
                                    Owes <span class="tabular font-mono">{{ money(owed(order)) }}</span>
                                </Badge>
                                <Badge
                                    v-else-if="owed(order) > 0"
                                    variant="outline"
                                    class="border-amber-500/60 bg-amber-500/10 text-amber-700 dark:text-amber-400 gap-1 font-semibold"
                                >
                                    <Clock class="size-3" />
                                    Pay later <span class="tabular font-mono">({{ money(owed(order)) }})</span>
                                </Badge>
                                <Badge v-else-if="order.sale_type === 'debt'" variant="outline">Debt · settled</Badge>
                                <Badge v-else-if="order.sale_type === 'myself'" variant="outline" class="gap-1">
                                    <Utensils class="size-3" />
                                    Myself
                                </Badge>
                                <span class="ml-auto text-[0.7rem] text-muted-foreground">
                                    {{ order.payments.map((p) => methodLabel(p.method)).join(' + ') || '—' }} ·
                                    <span class="tabular font-mono">{{ order.items_count }}</span> items
                                </span>
                            </div>
                        </Link>

                        <!-- Mobile action buttons -->
                        <div class="absolute bottom-2 right-2 flex items-center gap-1">
                            <Button
                                v-if="owed(order) > 0"
                                size="sm"
                                class="press h-8 px-2.5 gap-1 bg-primary text-primary-foreground text-xs font-semibold"
                                @click.stop.prevent="openPayModal(order)"
                            >
                                <CreditCard class="size-3.5" />
                                Pay
                            </Button>
                            <Link
                                :href="route('orders.show', { order: order.uuid, print: 1 })"
                                class="press flex size-8 items-center justify-center rounded-md text-muted-foreground"
                                :aria-label="`Print receipt for ${order.order_no}`"
                            >
                                <Printer class="size-4" />
                            </Link>
                        </div>
                    </li>
                </ul>

                <div v-if="orders.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Order</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Payment</TableHead>
                                <TableHead data-numeric class="text-right">Items</TableHead>
                                <TableHead data-numeric class="text-right">Total</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <tbody class="[&_tr:last-child]:border-0">
                            <TableRow v-for="order in orders.data" :key="order.id" class="group cursor-pointer">
                                <TableCell>
                                    <Link :href="route('orders.show', { order: order.uuid })" class="block">
                                        <span class="tabular font-mono text-xs font-medium">{{ order.order_no }}</span>
                                        <span v-if="order.customer" class="block text-[0.7rem] text-muted-foreground">
                                            {{ order.customer.name }}
                                        </span>
                                    </Link>
                                </TableCell>
                                <TableCell class="whitespace-nowrap text-sm text-muted-foreground">
                                    <span class="inline-flex items-center gap-1.5">
                                        <!-- Marks a sale that was rung up with no connection. -->
                                        <CloudOff v-if="order.created_offline_at" class="size-3 text-primary" />
                                        {{ soldAt(order) }}
                                    </span>
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{ order.cashier?.name ?? '—' }}
                                    <span v-if="order.register" class="text-[0.7rem]">· {{ order.register.name }}</span>
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{ order.payments.map((p) => methodLabel(p.method)).join(' + ') || '—' }}
                                </TableCell>
                                <TableCell data-numeric class="tabular text-right font-mono text-sm">
                                    {{ order.items_count }}
                                </TableCell>
                                <TableCell data-numeric class="text-right font-medium">
                                    <Money :value="order.total" :muted="false" />
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-wrap items-center gap-1">
                                        <Badge :variant="statusTone(order.status)" class="capitalize">{{ order.status }}</Badge>
                                        <!-- A live balance, not a flag: shown only while money is owed. -->
                                        <Badge v-if="owed(order) > 0 && order.sale_type === 'debt'" variant="destructive" class="gap-1">
                                            <HandCoins class="size-3" />
                                            Owes <span class="tabular font-mono">{{ money(owed(order)) }}</span>
                                        </Badge>
                                        <Badge
                                            v-else-if="owed(order) > 0"
                                            variant="outline"
                                            class="border-amber-500/60 bg-amber-500/10 text-amber-700 dark:text-amber-400 gap-1 font-semibold"
                                        >
                                            <Clock class="size-3" />
                                            Pay later · Unpaid
                                        </Badge>
                                        <Badge v-else-if="order.sale_type === 'debt'" variant="outline">Debt · settled</Badge>
                                        <Badge v-else-if="order.sale_type === 'myself'" variant="outline" class="gap-1">
                                            <Utensils class="size-3" />
                                            Myself
                                        </Badge>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Button
                                            v-if="owed(order) > 0"
                                            size="sm"
                                            class="press h-8 px-2.5 gap-1 bg-primary text-primary-foreground text-xs font-semibold hover:bg-primary/90"
                                            title="Collect payment for this order"
                                            @click.stop="openPayModal(order)"
                                        >
                                            <CreditCard class="size-3.5" />
                                            Pay
                                        </Button>
                                        <Link
                                            :href="route('orders.show', { order: order.uuid })"
                                            class="press flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                            :aria-label="`View ${order.order_no}`"
                                            title="View details"
                                        >
                                            <Eye class="size-4" />
                                        </Link>
                                        <!--
                                        Opens the detail with ?print=1, which prints on
                                        arrival. Printing from here directly would mean
                                        duplicating the whole receipt template into a
                                        list row that has not loaded the line items.
                                    -->
                                        <Link
                                            :href="route('orders.show', { order: order.uuid, print: 1 })"
                                            class="press flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                            :aria-label="`Print receipt for ${order.order_no}`"
                                            title="Print receipt"
                                        >
                                            <Printer class="size-4" />
                                        </Link>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </tbody>
                    </Table>
                </div>

                <EmptyState
                    v-else
                    :icon="ReceiptText"
                    title="No orders found"
                    description="Try clearing the filters. A sale queued offline only appears here once it has synced."
                />

                <Pagination :links="orders.links" :from="orders.from" :to="orders.to" :total="orders.total" :per-page="orders.per_page" />
            </div>
        </div>

        <!-- Pay modal for Pay Later / Unpaid orders -->
        <Dialog :open="payModalOpen" @update:open="(val) => (!val && (payModalOpen = false))">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <CreditCard class="size-5 text-primary" />
                        Pay order {{ payingOrder?.order_no }}
                    </DialogTitle>
                    <DialogDescription>
                        Collect and record payment for this order to complete it.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="payingOrder" class="space-y-4 py-2">
                    <div class="flex items-center justify-between rounded-xl border border-border bg-muted/40 p-3">
                        <div>
                            <p class="text-xs text-muted-foreground">Order total: {{ money(payingOrder.total) }}</p>
                            <p class="text-xs text-muted-foreground">Already paid: {{ money(payingOrder.paid_amount) }}</p>
                        </div>
                        <div class="text-right">
                            <span class="block text-xs font-semibold text-muted-foreground">Still owed</span>
                            <span class="font-mono text-xl font-bold text-primary">
                                {{ money(owed(payingOrder)) }}
                            </span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label>Payment method</Label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                type="button"
                                class="press flex flex-col items-center gap-1.5 rounded-lg border p-2.5 text-xs font-medium transition-colors"
                                :class="payMethod === 'cash' ? 'border-primary bg-primary/10 text-primary font-semibold' : 'border-border text-muted-foreground'"
                                @click="payMethod = 'cash'"
                            >
                                <Banknote class="size-4" />
                                Cash
                            </button>
                            <button
                                type="button"
                                class="press flex flex-col items-center gap-1.5 rounded-lg border p-2.5 text-xs font-medium transition-colors"
                                :class="payMethod === 'card' ? 'border-primary bg-primary/10 text-primary font-semibold' : 'border-border text-muted-foreground'"
                                @click="payMethod = 'card'"
                            >
                                <CreditCard class="size-4" />
                                Card
                            </button>
                            <button
                                type="button"
                                class="press flex flex-col items-center gap-1.5 rounded-lg border p-2.5 text-xs font-medium transition-colors"
                                :class="payMethod === 'qr' ? 'border-primary bg-primary/10 text-primary font-semibold' : 'border-border text-muted-foreground'"
                                @click="payMethod = 'qr'"
                            >
                                <QrCode class="size-4" />
                                QR
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label for="pay-amount">Amount to pay</Label>
                        <Input
                            id="pay-amount"
                            v-model.number="payAmount"
                            type="number"
                            step="any"
                            min="0.01"
                            :max="owed(payingOrder)"
                            class="font-mono"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <Label for="pay-reference">Reference / Note (optional)</Label>
                        <Input
                            id="pay-reference"
                            v-model="payRef"
                            placeholder="Terminal or transaction no."
                        />
                    </div>
                </div>

                <DialogFooter class="gap-2 sm:gap-0">
                    <Button variant="outline" type="button" @click="payModalOpen = false">
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="gap-1.5"
                        :disabled="isSubmitting || payAmount <= 0"
                        @click="submitPayment"
                    >
                        <Check class="size-4" />
                        {{ isSubmitting ? 'Saving…' : 'Confirm payment' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
