<script setup lang="ts">
import type { CurrencyDef } from '@/composables/useCurrency';
import { formatMoney } from '@/Pos/lib/money';
import type { PosCategory, PosProduct } from '@/Pos/types';
import { Layers, PackageOpen, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    products: PosProduct[];
    categories: PosCategory[];
    currency: CurrencyDef;
}>();

const emit = defineEmits<{ add: [product: PosProduct] }>();

const search = ref('');
const activeCategory = ref<number | null>(null);

const matches = (p: PosProduct, q: string) => p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || (p.barcode ?? '').includes(q);

/*
 * The grid shows base products only. A beer sold as a case, a six-pack and a
 * single is one tile with a chooser, not three tiles the cashier has to tell
 * apart mid-queue.
 *
 * Packs are still searchable and still scannable: typing a case's SKU, or
 * scanning its carton, surfaces the tile it belongs to.
 */
const packsByParent = computed(() => {
    const map = new Map<number, PosProduct[]>();

    for (const p of props.products) {
        if (p.parent_product_id === null) continue;
        const list = map.get(p.parent_product_id) ?? [];
        list.push(p);
        map.set(p.parent_product_id, list);
    }

    for (const list of map.values()) list.sort((a, b) => a.units_per_pack - b.units_per_pack);

    return map;
});

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.products.filter((p) => {
        if (p.parent_product_id !== null) return false;
        if (activeCategory.value !== null && p.category_id !== activeCategory.value) return false;
        if (!q) return true;

        return matches(p, q) || (packsByParent.value.get(p.id) ?? []).some((pack) => matches(pack, q));
    });
});

/** Every way this product can be sold, single first. */
const sellableAs = (product: PosProduct) => [product, ...(packsByParent.value.get(product.id) ?? [])];

/**
 * The dearest way to buy it, when there is more than one. The tile shows the
 * single price and this, so a cashier reads the span at a glance instead of
 * having to open the chooser to find out a case exists.
 */
function dearest(product: PosProduct): string | null {
    const options = sellableAs(product);
    if (options.length === 1) return null;

    const top = options.reduce((a, b) => (Number(b.sell_price) > Number(a.sell_price) ? b : a));

    return Number(top.sell_price) > Number(product.sell_price) ? top.sell_price : null;
}

/* Which tile has its chooser open. Null means none — only one at a time, so a
   mis-tap never leaves two panels covering the grid. */
const choosing = ref<number | null>(null);

function tap(product: PosProduct) {
    const options = sellableAs(product);

    // Nothing to choose between: straight into the cart, same as before.
    if (options.length === 1) {
        emit('add', product);

        return;
    }

    choosing.value = choosing.value === product.id ? null : product.id;
}

function choose(option: PosProduct) {
    choosing.value = null;
    emit('add', option);
}

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
                    :class="activeCategory === null ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'"
                    @click="activeCategory = null"
                >
                    All
                </button>
                <button
                    v-for="c in usableCategories"
                    :key="c.id"
                    type="button"
                    class="press h-9 shrink-0 rounded-full border px-4 text-sm font-medium"
                    :class="activeCategory === c.id ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground'"
                    @click="activeCategory = c.id"
                >
                    {{ c.name }}
                </button>
            </div>
        </div>

        <!-- Grid. No entrance animation: this re-renders on every keystroke,
             and a cashier tapping 60 items a minute needs it to feel instant. -->
        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div v-if="visible.length" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                <div v-for="product in visible" :key="product.id" class="relative">
                    <button
                        type="button"
                        class="press flex h-full w-full flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition-colors hover:border-primary/50 active:border-primary"
                        :aria-expanded="sellableAs(product).length > 1 ? choosing === product.id : undefined"
                        @click="tap(product)"
                    >
                        <div class="relative flex aspect-square w-full items-center justify-center overflow-hidden bg-muted/40">
                            <img
                                v-if="product.image"
                                :src="`/storage/${product.image}`"
                                :alt="product.name"
                                loading="lazy"
                                class="size-full object-cover"
                            />
                            <PackageOpen v-else class="size-7 text-muted-foreground/50" />

                            <!-- Says at a glance that this tile hides more than one price. -->
                            <span
                                v-if="sellableAs(product).length > 1"
                                class="absolute right-1.5 top-1.5 flex items-center gap-1 rounded-full bg-card/90 px-1.5 py-0.5 font-mono text-[0.65rem] font-medium text-muted-foreground backdrop-blur"
                            >
                                <Layers class="size-3" />
                                {{ sellableAs(product).length }}
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col gap-0.5 p-2.5">
                            <p class="line-clamp-2 text-sm font-medium leading-snug">{{ product.name }}</p>
                            <p class="tabular mt-auto font-mono text-base font-semibold text-primary">
                                {{ formatMoney(Number(product.sell_price), currency) }}
                                <span v-if="dearest(product)" class="text-xs font-normal text-muted-foreground">
                                    – {{ formatMoney(Number(dearest(product)), currency) }}
                                </span>
                            </p>
                            <p class="tabular font-mono text-[0.7rem]" :class="stockTone(product)">
                                {{ product.track_stock ? `${product.stock_qty} ${product.unit}` : product.unit }}
                            </p>
                        </div>
                    </button>

                    <!-- Pack chooser. Overlays its own tile rather than opening a
                         dialog: the cashier's thumb is already here. -->
                    <div
                        v-if="choosing === product.id"
                        class="animate-scale absolute inset-y-0 left-0 z-10 flex w-max min-w-full max-w-[15rem] flex-col overflow-hidden rounded-xl border border-primary bg-card shadow-lg"
                    >
                        <p class="truncate border-b border-border px-2.5 py-2 text-xs font-medium text-muted-foreground">
                            {{ product.name }}
                        </p>

                        <div class="min-h-0 flex-1 overflow-y-auto">
                            <button
                                v-for="option in sellableAs(product)"
                                :key="option.id"
                                type="button"
                                class="row-press flex w-full items-baseline justify-between gap-2 px-2.5 py-2 text-left"
                                @click="choose(option)"
                            >
                                <span class="min-w-0 flex-1 text-sm leading-snug">
                                    {{ option.id === product.id ? `1 ${product.unit}` : option.name }}
                                </span>
                                <span class="tabular shrink-0 font-mono text-sm font-semibold text-primary">
                                    {{ formatMoney(Number(option.sell_price), currency) }}
                                </span>
                            </button>
                        </div>

                        <button type="button" class="border-t border-border py-1.5 text-xs text-muted-foreground" @click="choosing = null">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <div v-else class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <PackageOpen class="size-8 text-muted-foreground/40" />
                <p class="font-medium">Nothing matches</p>
                <p class="text-sm text-muted-foreground">Try another word, or clear the filter.</p>
            </div>
        </div>
    </div>
</template>
