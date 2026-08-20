<script setup lang="ts">
import { formatMoney } from '@/Pos/lib/money';
import type { PosSettings, StoredOrder } from '@/Pos/types';
import { computed } from 'vue';

const props = defineProps<{
    order: StoredOrder;
    settings: PosSettings;
}>();

const currency = computed(() => props.settings.currency_symbol ?? '$');

/**
 * Before an order syncs it has no server-issued number, so the receipt shows
 * a short form of the client_uuid instead. That short ref is what a customer
 * quotes if they come back before the queue has drained — and it is the same
 * key the server dedupes on, so it always resolves to the right sale.
 */
const reference = computed(() =>
    props.order.order_no
        ? props.order.order_no
        : `TMP-${props.order.client_uuid.slice(0, 8).toUpperCase()}`,
);

const isProvisional = computed(() => !props.order.order_no);

const soldAt = computed(() =>
    new Date(props.order.created_offline_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);

const paymentLabel = computed(() =>
    props.order.payments.map((p) => p.method.toUpperCase()).join(' + '),
);

const money = (value: string | number) => formatMoney(Number(value), currency.value);
</script>

<template>
    <!-- 80mm thermal width. The print stylesheet in app.css hides everything
         except this block, so window.print() produces just the slip. -->
    <article
        class="receipt-sheet mx-auto w-[302px] bg-white p-4 font-mono text-[11px] leading-snug text-black"
    >
        <header class="text-center">
            <h1 class="text-sm font-bold uppercase tracking-wide">{{ settings.receipt_header }}</h1>
            <p class="mt-0.5">{{ order.receipt.store }}</p>
        </header>

        <div class="my-2 border-t border-dashed border-black/40" />

        <dl class="space-y-0.5">
            <div class="flex justify-between">
                <dt>Receipt</dt>
                <dd class="font-bold">{{ reference }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>Date</dt>
                <dd>{{ soldAt }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>Cashier</dt>
                <dd>{{ order.receipt.cashier }}</dd>
            </div>
        </dl>

        <div class="my-2 border-t border-dashed border-black/40" />

        <ul class="space-y-1">
            <li v-for="(item, i) in order.items" :key="i">
                <p>{{ item.product_name }}</p>
                <div class="flex justify-between">
                    <span>{{ item.qty }} × {{ money(item.unit_price) }}</span>
                    <span>{{ money(Number(item.unit_price) * item.qty - Number(item.discount)) }}</span>
                </div>
                <div v-if="Number(item.discount) > 0" class="flex justify-between">
                    <span class="pl-3">Discount</span>
                    <span>− {{ money(item.discount) }}</span>
                </div>
            </li>
        </ul>

        <div class="my-2 border-t border-dashed border-black/40" />

        <dl class="space-y-0.5">
            <div class="flex justify-between">
                <dt>Subtotal</dt>
                <dd>{{ money(order.receipt.subtotal) }}</dd>
            </div>
            <div v-if="Number(order.discount_amount) > 0" class="flex justify-between">
                <dt>Discount</dt>
                <dd>− {{ money(order.discount_amount) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>Tax</dt>
                <dd>{{ money(order.receipt.tax) }}</dd>
            </div>
            <div class="mt-1 flex justify-between border-t border-black/40 pt-1 text-sm font-bold">
                <dt>TOTAL</dt>
                <dd>{{ money(order.receipt.total) }}</dd>
            </div>
            <div class="flex justify-between pt-1">
                <dt>Paid ({{ paymentLabel }})</dt>
                <dd>{{ money(order.receipt.paid) }}</dd>
            </div>
            <div v-if="Number(order.receipt.change) > 0" class="flex justify-between">
                <dt>Change</dt>
                <dd>{{ money(order.receipt.change) }}</dd>
            </div>
        </dl>

        <div class="my-2 border-t border-dashed border-black/40" />

        <p v-if="isProvisional" class="text-center text-[10px]">
            *** PROVISIONAL — not yet synced ***
        </p>

        <footer class="mt-1 text-center">
            <p v-if="settings.receipt_footer">{{ settings.receipt_footer }}</p>
        </footer>
    </article>
</template>
