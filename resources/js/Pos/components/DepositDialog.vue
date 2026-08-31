<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CurrencyDef } from '@/composables/useCurrency';
import { formatMoney } from '@/Pos/lib/money';
import { HandCoins } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/*
 * The moment between "it's a debt" and "the drawer opens": plenty of customers
 * put SOME money down. Asked at pay time, not when the customer is picked,
 * because the deposit only means something once the bill is final.
 */
const props = defineProps<{
    open: boolean;
    total: number;
    currency: CurrencyDef;
    customerName: string | null;
}>();

const emit = defineEmits<{ close: []; confirm: [deposit: number] }>();

const raw = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) raw.value = '';
    },
);

const deposit = computed(() => Number(raw.value) || 0);
const owed = computed(() => Math.max(0, props.total - deposit.value));

/* A deposit that covers the bill is not a debt — that sale is a customer sale. */
const coversEverything = computed(() => deposit.value >= props.total && props.total > 0);
const validDeposit = computed(() => deposit.value > 0 && !coversEverything.value);
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-w-sm">
            <DialogHeader>
                <DialogTitle>Money down?</DialogTitle>
                <DialogDescription>
                    {{ customerName ?? 'The customer' }} owes
                    <strong class="tabular font-mono text-foreground">{{ formatMoney(total, currency) }}</strong
                    >. Take part of it now, or put the whole bill in debt.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2 py-2">
                <Label for="deposit">Paying now</Label>
                <Input
                    id="deposit"
                    v-model="raw"
                    type="number"
                    min="0"
                    :step="currency.decimals > 0 ? '0.01' : '1'"
                    inputmode="decimal"
                    placeholder="0"
                    class="tabular font-mono text-lg"
                    autofocus
                />

                <p v-if="coversEverything" class="text-xs text-destructive">That covers the whole bill — ring it up as a Customer sale instead.</p>
                <p v-else class="tabular font-mono text-xs text-muted-foreground">
                    Still owed: <strong class="text-foreground">{{ formatMoney(owed, currency) }}</strong>
                </p>
            </div>

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" class="press" @click="emit('confirm', 0)">All in debt</Button>
                <Button type="button" class="press" :disabled="!validDeposit" @click="emit('confirm', deposit)">
                    <HandCoins class="size-4" />
                    Take {{ formatMoney(deposit, currency) }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
