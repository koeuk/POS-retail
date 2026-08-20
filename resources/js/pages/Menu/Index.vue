<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Head } from '@inertiajs/vue3';
import { Search, UtensilsCrossed } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface MenuProduct {
    id: number;
    name: string;
    description: string | null;
    image: string | null;
    unit: string;
    category_id: number;
    category_name: string | null;
    price: number;
}

const props = defineProps<{
    products: MenuProduct[];
    categories: { id: number; name: string }[];
    filters: { search: string; category: number | null };
    shop: { name: string; footer: string | null; currency: string };
}>();

/*
 * Filtering happens client-side. A menu is a small, fully-loaded list, so
 * a round-trip per keystroke would only make it feel slower.
 */
const search = ref(props.filters.search ?? '');
const activeCategory = ref<number | null>(props.filters.category);

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.products.filter((p) => {
        if (activeCategory.value !== null && p.category_id !== activeCategory.value) {
            return false;
        }
        if (!q) return true;
        return p.name.toLowerCase().includes(q) || (p.description ?? '').toLowerCase().includes(q);
    });
});

/** Grouped so the page reads like a printed menu rather than a search result. */
const grouped = computed(() => {
    const groups = new Map<number, { name: string; items: MenuProduct[] }>();

    for (const product of visible.value) {
        if (!groups.has(product.category_id)) {
            groups.set(product.category_id, {
                name: product.category_name ?? 'Other',
                items: [],
            });
        }
        groups.get(product.category_id)!.items.push(product);
    }

    return [...groups.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const money = (value: number) =>
    `${props.shop.currency}${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const year = new Date().getFullYear();
</script>

<template>
    <Head :title="`Menu — ${shop.name}`" />

    <div class="min-h-dvh bg-background text-foreground">
        <!-- Masthead -->
        <header class="border-b border-border">
            <div class="mx-auto max-w-5xl px-5 py-12 text-center md:py-16">
                <p class="animate-fade font-mono text-[0.7rem] uppercase tracking-[0.28em] text-primary">Our menu</p>
                <h1 class="animate-rise mt-3 font-display text-4xl font-bold tracking-tight md:text-6xl" style="animation-delay: 60ms">
                    {{ shop.name }}
                </h1>
                <p class="animate-rise mx-auto mt-3 max-w-md text-sm text-muted-foreground" style="animation-delay: 120ms">
                    Everything we stock, with current prices. All prices include tax.
                </p>
            </div>
        </header>

        <!-- Sticky filter bar -->
        <div class="sticky top-0 z-20 border-b border-border bg-background/85 backdrop-blur">
            <div class="mx-auto max-w-5xl px-5 py-3">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" type="search" placeholder="Search the menu…" class="pl-9" aria-label="Search the menu" />
                </div>

                <nav v-if="categories.length" class="mt-3 flex gap-1.5 overflow-x-auto pb-1">
                    <button
                        type="button"
                        class="press shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            activeCategory === null
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                        "
                        @click="activeCategory = null"
                    >
                        Everything
                    </button>
                    <button
                        v-for="c in categories"
                        :key="c.id"
                        type="button"
                        class="press shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors"
                        :class="
                            activeCategory === c.id
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                        "
                        @click="activeCategory = c.id"
                    >
                        {{ c.name }}
                    </button>
                </nav>
            </div>
        </div>

        <!-- Menu -->
        <main class="mx-auto max-w-5xl px-5 py-10">
            <section v-for="group in grouped" :key="group.name" class="mb-12 last:mb-0">
                <div class="mb-5 flex items-baseline gap-3">
                    <h2 class="font-display text-2xl font-semibold tracking-tight">
                        {{ group.name }}
                    </h2>
                    <span class="h-px flex-1 bg-border" />
                    <span class="tabular font-mono text-xs text-muted-foreground">
                        {{ group.items.length }}
                    </span>
                </div>

                <ul class="stagger grid gap-3 sm:grid-cols-2">
                    <li v-for="item in group.items" :key="item.id" class="lift flex items-center gap-4 rounded-xl border border-border bg-card p-3">
                        <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/40">
                            <img v-if="item.image" :src="`/storage/${item.image}`" :alt="item.name" loading="lazy" class="size-full object-cover" />
                            <UtensilsCrossed v-else class="size-5 text-muted-foreground/60" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <h3 class="truncate font-medium leading-snug">{{ item.name }}</h3>
                            <p v-if="item.description" class="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                {{ item.description }}
                            </p>
                            <p v-else class="font-mono text-[0.7rem] uppercase tracking-wider text-muted-foreground/70">per {{ item.unit }}</p>
                        </div>

                        <p class="tabular shrink-0 font-mono text-base font-semibold text-primary">
                            {{ money(item.price) }}
                        </p>
                    </li>
                </ul>
            </section>

            <div v-if="!grouped.length" class="animate-scale py-20 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground">
                    <Search class="size-5" />
                </div>
                <p class="mt-3 font-display text-lg font-semibold">Nothing matches that</p>
                <p class="mt-1 text-sm text-muted-foreground">Try a different word, or browse everything.</p>
                <button
                    type="button"
                    class="press mt-4 rounded-full border border-border px-4 py-2 text-sm transition-colors hover:bg-accent"
                    @click="((search = ''), (activeCategory = null))"
                >
                    Show everything
                </button>
            </div>
        </main>

        <footer class="border-t border-border">
            <div class="mx-auto max-w-5xl px-5 py-8 text-center">
                <p v-if="shop.footer" class="text-sm text-muted-foreground">{{ shop.footer }}</p>
                <p class="mt-1 font-mono text-[0.7rem] uppercase tracking-[0.2em] text-muted-foreground/60">{{ shop.name }} · {{ year }}</p>
            </div>
        </footer>
    </div>
</template>
