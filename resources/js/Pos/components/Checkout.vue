<script setup lang="ts">
import { useCart } from '@/Pos/composables/useCart';
import { formatMoney } from '@/Pos/lib/money';
import { CreditCard, Percent } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{ currency: string }>();
const emit = defineEmits<{ pay: [] }>();

const cart = useCart();

const discountInput = computed({
    get: () => (cart.orderDiscount ? String(cart.orderDiscount) : ''),
    set: (value: string) => cart.setOrderDiscount(Number(value) || 0),
});
</script>

<template>
    <div class="shrink-0 border-t border-border bg-card">
        <div class="space-y-3 p-4">
            <!-- Order-level discount. Flat amount, never a percentage —
                 the server spreads it across lines before tax. -->
            <div class="flex items-center gap-2">
                <label for="order-discount" class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <Percent class="size-3.5" />
                    Discount
                </label>
                <input
                    id="order-discount"
                    v-model="discountInput"
                    type="number"
                    min="0"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="0.00"
                    :disabled="cart.isEmpty"
                    class="tabular h-9 w-full rounded-md border border-input bg-background px-2 text-right font-mono text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                />
            </div>

            <dl class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Subtotal</dt>
                    <dd class="tabular font-mono">{{ formatMoney(cart.totals.subtotal, currency) }}</dd>
                </div>
                <div v-if="cart.totals.discount > 0" class="flex justify-between text-primary">
                    <dt>Discount</dt>
                    <dd class="tabular font-mono">− {{ formatMoney(cart.totals.discount, currency) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Tax</dt>
                    <dd class="tabular font-mono">{{ formatMoney(cart.totals.tax, currency) }}</dd>
                </div>
                <div class="flex items-baseline justify-between border-t border-border pt-2">
                    <dt class="font-display text-base font-semibold">Total</dt>
                    <dd class="tabular font-mono text-2xl font-bold text-primary">
                        {{ formatMoney(cart.totals.total, currency) }}
                    </dd>
                </div>
            </dl>

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
