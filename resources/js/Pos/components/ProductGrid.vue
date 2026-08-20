<script setup lang="ts">
import { formatMoney } from '@/Pos/lib/money';
import type { PosCategory, PosProduct } from '@/Pos/types';
import { PackageOpen, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    products: PosProduct[];
    categories: PosCategory[];
    currency: string;
}>();

const emit = defineEmits<{ add: [product: PosProduct] }>();

const search = ref('');
const activeCategory = ref<number | null>(null);

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.products.filter((p) => {
        if (activeCategory.value !== null && p.category_id !== activeCategory.value) return false;
        if (!q) return true;
        return (
            p.name.toLowerCase().includes(q) ||
            p.sku.toLowerCase().includes(q) ||
            (p.barcode ?? '').includes(q)
        );
    });
});

/** Only categories that actually have something in the current filter. */
const usableCategories = computed(() => {
    const ids = new Set(props.products.map((p) => p.category_id));
    return props.categories.filter((c) => ids.has(c.id));
});

function stockTone(product: PosProduct): string {
    if (!product.track_stock) return 'text-muted-foreground';
    if (product.stock_qty <= 0) return 'text-destructive';
    if (product.stock_qty <= 5) return 'text-primary';
    return 'text-muted-foreground';
}

defineExpose({ focusSearch: () => document.getElementById('pos-search')?.focus() });
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Filters -->
        <div class="shrink-0 space-y-2 border-b border-border p-3">
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" />
                <input
                    id="pos-search"
                    v-model="search"
                    type="search"
                    placeholder="Search or scan…"
                    autocomplete="off"
                    class="h-12 w-full rounded-lg border border-input bg-background pl-11 pr-3 text-base outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring"
                />
            </div>

            <div class="flex gap-1.5 overflow-x-auto pb-1">
                <button
                    type="button"
                    class="press h-9 shrink-0 rounded-full border px-4 text-sm font-medium"
                    :class="
                        activeCategory === null
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border text-muted-foreground'
                    "
                    @click="activeCategory = null"
                >
                    All
                </button>
                <button
                    v-for="c in usableCategories"
                    :key="c.id"
                    type="button"
                    class="press h-9 shrink-0 rounded-full border px-4 text-sm font-medium"
                    :class="
                        activeCategory === c.id
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border text-muted-foreground'
                    "
                    @click="activeCategory = c.id"
                >
                    {{ c.name }}
                </button>
            </div>
        </div>

        <!-- Grid. No entrance animation: this re-renders on every keystroke,
             and a cashier tapping 60 items a minute needs it to feel instant. -->
        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div
                v-if="visible.length"
                class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            >
                <button
                    v-for="product in visible"
                    :key="product.id"
                    type="button"
                    class="press flex flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition-colors hover:border-primary/50 active:border-primary"
                    @click="emit('add', product)"
                >
                    <div class="flex aspect-square w-full items-center justify-center overflow-hidden bg-muted/40">
                        <img
                            v-if="product.image"
                            :src="`/storage/${product.image}`"
                            :alt="product.name"
                            loading="lazy"
                            class="size-full object-cover"
                        />
                        <PackageOpen v-else class="size-7 text-muted-foreground/50" />
                    </div>

                    <div class="flex flex-1 flex-col gap-0.5 p-2.5">
                        <p class="line-clamp-2 text-sm font-medium leading-snug">{{ product.name }}</p>
                        <p class="tabular mt-auto font-mono text-base font-semibold text-primary">
                            {{ formatMoney(Number(product.sell_price), currency) }}
                        </p>
                        <p class="tabular font-mono text-[0.7rem]" :class="stockTone(product)">
                            {{ product.track_stock ? `${product.stock_qty} ${product.unit}` : product.unit }}
                        </p>
                    </div>
                </button>
            </div>

            <div v-else class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <PackageOpen class="size-8 text-muted-foreground/40" />
                <p class="font-medium">Nothing matches</p>
                <p class="text-sm text-muted-foreground">Try another word, or clear the filter.</p>
            </div>
        </div>
    </div>
</template>
