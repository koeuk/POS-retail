<script setup lang="ts">
import { useCart } from '@/Pos/composables/useCart';
import { formatMoney } from '@/Pos/lib/money';
import { CreditCard } from 'lucide-vue-next';

defineProps<{ currency: string }>();
const emit = defineEmits<{ pay: [] }>();

const cart = useCart();
</script>

<template>
    <!--
        One number at the till. A cashier reads the figure the customer has to
        pay and nothing else; the line-by-line breakdown belongs on the receipt
        and in Reports, where someone is actually reconciling.
    -->
    <div class="shrink-0 border-t border-border bg-card">
        <div class="space-y-3 p-4">
            <div class="flex items-baseline justify-between">
                <p class="font-display text-base font-semibold">Total</p>
                <p class="tabular font-mono text-2xl font-bold text-primary">
                    {{ formatMoney(cart.totals.total, currency) }}
                </p>
            </div>

            <button
                type="button"
                :disabled="cart.isEmpty"
                class="press flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary text-base font-semibold text-primary-foreground disabled:opacity-40"
                @click="emit('pay')"
            >
                <CreditCard class="size-5" />
                Complete sale
            </button>
        </div>
    </div>
</template>
