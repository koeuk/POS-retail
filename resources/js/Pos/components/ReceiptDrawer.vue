<script setup lang="ts">
import Receipt from '@/Pos/components/Receipt.vue';
import { printReceipt } from '@/Pos/composables/usePrint';
import { recentOrders } from '@/Pos/db/dexie';
import { formatMoney } from '@/Pos/lib/money';
import type { PosSettings, StoredOrder } from '@/Pos/types';
import { CloudOff, Printer, ReceiptText, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    settings: PosSettings;
    /** Opened straight after a sale, this one is preselected. */
    focus?: StoredOrder | null;
}>();

const emit = defineEmits<{ close: [] }>();

const orders = ref<StoredOrder[]>([]);
const selected = ref<StoredOrder | null>(null);

async function load() {
    // Straight from IndexedDB — reprinting must work with no network.
    orders.value = await recentOrders(30);
    selected.value = props.focus ?? orders.value[0] ?? null;
}

watch(() => props.open, (open) => open && load(), { immediate: true });

const soldAt = (iso: string) =>
    new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-from-class="opacity-0"
            enter-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
        >
            <div v-if="open" class="fixed inset-0 z-50 flex justify-end bg-black/50">
                <Transition
                    appear
                    enter-from-class="translate-x-4 opacity-0"
                    enter-active-class="transition duration-200 ease-out-quint"
                >
                    <div class="flex h-full w-full max-w-3xl flex-col bg-card shadow-2xl sm:flex-row">
                        <!-- Sale list -->
                        <div class="flex min-h-0 w-full flex-col border-b border-border sm:w-64 sm:border-b-0 sm:border-r">
                            <header class="flex shrink-0 items-center justify-between border-b border-border px-3 py-2.5">
                                <h2 class="flex items-center gap-2 text-sm font-semibold">
                                    <ReceiptText class="size-4 text-primary" />
                                    Recent sales
                                </h2>
                                <button
                                    type="button"
                                    class="press rounded-md p-1 text-muted-foreground sm:hidden"
                                    aria-label="Close"
                                    @click="emit('close')"
                                >
                                    <X class="size-4" />
                                </button>
                            </header>

                            <ul v-if="orders.length" class="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
                                <li v-for="order in orders" :key="order.client_uuid">
                                    <button
                                        type="button"
                                        class="press flex w-full items-start gap-2 px-3 py-2.5 text-left transition-colors"
                                        :class="selected?.client_uuid === order.client_uuid ? 'bg-accent' : ''"
                                        @click="selected = order"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <p class="tabular truncate font-mono text-xs font-medium">
                                                {{ order.order_no ?? `TMP-${order.client_uuid.slice(0, 8).toUpperCase()}` }}
                                            </p>
                                            <p class="text-[0.7rem] text-muted-foreground">
                                                {{ soldAt(order.created_offline_at) }}
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="tabular font-mono text-xs font-semibold">
                                                {{ formatMoney(Number(order.total), settings.currency_symbol) }}
                                            </p>
                                            <CloudOff
                                                v-if="order.state === 'pending_sync'"
                                                class="ml-auto mt-0.5 size-3 text-primary"
                                            />
                                        </div>
                                    </button>
                                </li>
                            </ul>

                            <p v-else class="flex-1 p-6 text-center text-sm text-muted-foreground">
                                No sales on this device yet.
                            </p>
                        </div>

                        <!-- Preview -->
                        <div class="flex min-h-0 flex-1 flex-col">
                            <header class="hidden shrink-0 items-center justify-between border-b border-border px-4 py-2.5 sm:flex">
                                <p class="text-sm font-semibold">Receipt</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        :disabled="!selected"
                                        class="press flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-40"
                                        @click="printReceipt()"
                                    >
                                        <Printer class="size-4" />
                                        Print
                                    </button>
                                    <button
                                        type="button"
                                        class="press rounded-md p-1.5 text-muted-foreground"
                                        aria-label="Close"
                                        @click="emit('close')"
                                    >
                                        <X class="size-4" />
                                    </button>
                                </div>
                            </header>

                            <div class="min-h-0 flex-1 overflow-y-auto bg-muted/30 p-4">
                                <Receipt v-if="selected" :order="selected" :settings="settings" />
                                <p v-else class="pt-10 text-center text-sm text-muted-foreground">
                                    Pick a sale to see its receipt.
                                </p>
                            </div>

                            <div class="shrink-0 border-t border-border p-3 sm:hidden">
                                <button
                                    type="button"
                                    :disabled="!selected"
                                    class="press flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-primary text-sm font-semibold text-primary-foreground disabled:opacity-40"
                                    @click="printReceipt()"
                                >
                                    <Printer class="size-4" />
                                    Print receipt
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
