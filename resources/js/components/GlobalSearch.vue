<script setup lang="ts">
import { useCurrency } from '@/composables/useCurrency';
import { cn, imageSrc } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import { onClickOutside } from '@vueuse/core';
import {
    ArrowRight,
    Boxes,
    Building2,
    ChartNoAxesColumn,
    CornerDownLeft,
    HandCoins,
    History,
    LayoutDashboard,
    Loader2,
    PackageSearch,
    Receipt,
    ScanBarcode,
    Search,
    Shapes,
    Store,
    UserCheck,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch, type HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const { money } = useCurrency();

interface SearchResultItem {
    id?: number | string;
    title: string;
    subtitle?: string;
    url: string;
    price?: number | string;
    unit?: string;
    image?: string | null;
    total?: number | string;
    status?: string;
    icon?: string;
}

interface SearchResponse {
    navigation: SearchResultItem[];
    products: SearchResultItem[];
    orders: SearchResultItem[];
    customers: SearchResultItem[];
}

const iconComponents: Record<string, any> = {
    LayoutDashboard,
    ScanBarcode,
    Receipt,
    HandCoins,
    Boxes,
    Shapes,
    PackageSearch,
    Users,
    Building2,
    ChartNoAxesColumn,
    UserCheck,
    History,
    Store,
};

const searchQuery = ref('');
const isOpen = ref(false);
const isLoading = ref(false);
const selectedIndex = ref(-1);
const containerRef = ref<HTMLElement | null>(null);
const inputRef = ref<HTMLInputElement | null>(null);

const results = ref<SearchResponse>({
    navigation: [],
    products: [],
    orders: [],
    customers: [],
});

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
let currentSeq = 0;

const hasResults = computed(() => {
    return (
        results.value.navigation.length > 0 ||
        results.value.products.length > 0 ||
        results.value.orders.length > 0 ||
        results.value.customers.length > 0
    );
});

/** Flat array of all currently visible items for up/down keyboard traversal */
const flatResults = computed<SearchResultItem[]>(() => {
    return [...results.value.navigation, ...results.value.products, ...results.value.orders, ...results.value.customers];
});

async function fetchResults(query: string) {
    const seq = ++currentSeq;
    isLoading.value = true;

    try {
        const url = `${route('global-search')}?q=${encodeURIComponent(query)}`;
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(String(response.status));
        const data: SearchResponse = await response.json();

        if (seq === currentSeq) {
            results.value = {
                navigation: data.navigation ?? [],
                products: data.products ?? [],
                orders: data.orders ?? [],
                customers: data.customers ?? [],
            };
            selectedIndex.value = -1;
        }
    } catch {
        if (seq === currentSeq) {
            results.value = { navigation: [], products: [], orders: [], customers: [] };
        }
    } finally {
        if (seq === currentSeq) {
            isLoading.value = false;
        }
    }
}

watch(searchQuery, (val) => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        void fetchResults(val.trim());
    }, 200);
});

function onFocus() {
    isOpen.value = true;
    if (!hasResults.value && !isLoading.value) {
        void fetchResults(searchQuery.value.trim());
    }
}

function clearSearch() {
    searchQuery.value = '';
    selectedIndex.value = -1;
    inputRef.value?.focus();
    void fetchResults('');
}

function navigateTo(url: string) {
    isOpen.value = false;
    router.visit(url);
}

function handleSubmit() {
    if (selectedIndex.value >= 0 && flatResults.value[selectedIndex.value]) {
        navigateTo(flatResults.value[selectedIndex.value].url);
        return;
    }

    const q = searchQuery.value.trim();
    if (!q) return;

    isOpen.value = false;
    inputRef.value?.blur();

    // If query starts with order prefix 'S' or '#', redirect to orders list
    if (/^[sS]\d|#/.test(q)) {
        router.visit(route('orders.index', { filter: { search: q } }));
    } else {
        router.visit(route('products.index', { filter: { search: q } }));
    }
}

function handleKeyDown(e: KeyboardEvent) {
    if (!isOpen.value) {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            isOpen.value = true;
            return;
        }
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (flatResults.value.length > 0) {
            selectedIndex.value = (selectedIndex.value + 1) % flatResults.value.length;
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (flatResults.value.length > 0) {
            selectedIndex.value = selectedIndex.value <= 0 ? flatResults.value.length - 1 : selectedIndex.value - 1;
        }
    } else if (e.key === 'Escape') {
        isOpen.value = false;
        inputRef.value?.blur();
    }
}

function getFlatIndex(item: SearchResultItem): number {
    return flatResults.value.findIndex((f) => f === item);
}

// Global hotkeys (Cmd+K / Ctrl+K or '/')
function onGlobalKeyDown(e: KeyboardEvent) {
    const isTargetInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes((document.activeElement?.tagName || '').toUpperCase());

    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        isOpen.value = true;
        nextTick(() => {
            inputRef.value?.focus();
            inputRef.value?.select();
        });
    } else if (e.key === '/' && !isTargetInput) {
        e.preventDefault();
        isOpen.value = true;
        nextTick(() => {
            inputRef.value?.focus();
        });
    }
}

onClickOutside(containerRef, () => {
    isOpen.value = false;
});

onMounted(() => {
    window.addEventListener('keydown', onGlobalKeyDown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onGlobalKeyDown);
    if (debounceTimer) clearTimeout(debounceTimer);
});
</script>

<template>
    <div ref="containerRef" :class="cn('relative w-full max-w-[540px]', props.class)">
        <form @submit.prevent="handleSubmit" class="relative flex items-center">
            <Search class="pointer-events-none absolute left-3.5 size-5 text-primary/70" />
            <input
                ref="inputRef"
                v-model="searchQuery"
                type="text"
                placeholder="Search products, orders, customers…"
                class="shadow-2xs h-11 w-full rounded-full border-2 border-primary/35 bg-background/95 pl-11 pr-16 text-sm text-foreground transition-all placeholder:text-muted-foreground/70 hover:border-primary/60 focus:border-primary focus:bg-background focus:outline-none focus:ring-4 focus:ring-primary/15 dark:border-primary/50 dark:bg-muted/40 sm:text-[15px]"
                autocomplete="off"
                spellcheck="false"
                @focus="onFocus"
                @keydown="handleKeyDown"
            />
            <div class="absolute right-3 flex items-center gap-1.5">
                <Loader2 v-if="isLoading" class="size-4.5 animate-spin text-primary/70" />
                <button
                    v-if="searchQuery"
                    type="button"
                    class="flex size-6 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                    title="Clear search"
                    aria-label="Clear search"
                    @click="clearSearch"
                >
                    <X class="size-4" />
                </button>
                <kbd
                    v-else
                    class="shadow-2xs pointer-events-none hidden select-none items-center gap-0.5 rounded-md border border-primary/25 bg-background/90 px-2 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground lg:inline-flex"
                >
                    ⌘K
                </kbd>
            </div>
        </form>

        <!-- Dropdown panel -->
        <div
            v-if="isOpen"
            class="absolute left-0 top-full z-50 mt-2 w-full min-w-[22rem] overflow-hidden rounded-2xl border-2 border-primary/20 bg-popover/95 text-popover-foreground shadow-2xl backdrop-blur-md duration-100 animate-in fade-in-0 zoom-in-95 dark:border-primary/35 sm:min-w-[28rem] md:min-w-[34rem]"
        >
            <div class="max-h-[26rem] space-y-2 overflow-y-auto p-1.5">
                <!-- Navigation section -->
                <div v-if="results.navigation.length > 0" class="space-y-0.5">
                    <p class="px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Navigation</p>
                    <div
                        v-for="item in results.navigation"
                        :key="item.url"
                        class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors"
                        :class="
                            selectedIndex === getFlatIndex(item)
                                ? 'bg-accent font-medium text-accent-foreground'
                                : 'text-foreground hover:bg-muted/70'
                        "
                        @click="navigateTo(item.url)"
                    >
                        <div class="flex items-center gap-2.5 truncate">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-foreground/80">
                                <component :is="iconComponents[item.icon || 'LayoutDashboard'] || LayoutDashboard" class="size-4" />
                            </div>
                            <div class="truncate">
                                <div class="truncate leading-tight">{{ item.title }}</div>
                                <div v-if="item.subtitle" class="truncate text-xs text-muted-foreground">
                                    {{ item.subtitle }}
                                </div>
                            </div>
                        </div>
                        <CornerDownLeft v-if="selectedIndex === getFlatIndex(item)" class="size-3.5 shrink-0 text-muted-foreground" />
                    </div>
                </div>

                <!-- Products section -->
                <div v-if="results.products.length > 0" class="space-y-0.5">
                    <div class="flex items-center justify-between px-2.5 py-1">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Products</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="navigateTo(route('products.index', { filter: { search: searchQuery } }))"
                        >
                            View all
                        </button>
                    </div>
                    <div
                        v-for="item in results.products"
                        :key="item.id"
                        class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors"
                        :class="selectedIndex === getFlatIndex(item) ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted/70'"
                        @click="navigateTo(item.url)"
                    >
                        <div class="flex items-center gap-2.5 truncate">
                            <div class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border/50 bg-muted">
                                <img v-if="item.image" :src="imageSrc(item.image)" :alt="item.title" class="size-full object-cover" />
                                <Boxes v-else class="size-4 text-muted-foreground" />
                            </div>
                            <div class="truncate">
                                <div class="truncate font-medium leading-tight">{{ item.title }}</div>
                                <div class="truncate text-xs text-muted-foreground">{{ item.subtitle }}</div>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="font-semibold tabular-nums text-foreground">{{ money(item.price) }}</span>
                            <span v-if="item.unit" class="ml-0.5 text-xs text-muted-foreground">/{{ item.unit }}</span>
                        </div>
                    </div>
                </div>

                <!-- Orders section -->
                <div v-if="results.orders.length > 0" class="space-y-0.5">
                    <div class="flex items-center justify-between px-2.5 py-1">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Orders</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="navigateTo(route('orders.index', { filter: { search: searchQuery } }))"
                        >
                            View all
                        </button>
                    </div>
                    <div
                        v-for="item in results.orders"
                        :key="item.id"
                        class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors"
                        :class="selectedIndex === getFlatIndex(item) ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted/70'"
                        @click="navigateTo(item.url)"
                    >
                        <div class="flex items-center gap-2.5 truncate">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-foreground/80">
                                <Receipt class="size-4" />
                            </div>
                            <div class="truncate">
                                <div class="truncate font-medium leading-tight">{{ item.title }}</div>
                                <div class="truncate text-xs text-muted-foreground">{{ item.subtitle }}</div>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <span class="font-semibold tabular-nums text-foreground">{{ money(item.total) }}</span>
                            <div v-if="item.status" class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">
                                {{ item.status }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customers section -->
                <div v-if="results.customers.length > 0" class="space-y-0.5">
                    <div class="flex items-center justify-between px-2.5 py-1">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Customers</p>
                        <button
                            type="button"
                            class="text-xs text-primary hover:underline"
                            @click="navigateTo(route('customers.index', { filter: { search: searchQuery } }))"
                        >
                            View all
                        </button>
                    </div>
                    <div
                        v-for="item in results.customers"
                        :key="item.id"
                        class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-sm transition-colors"
                        :class="selectedIndex === getFlatIndex(item) ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted/70'"
                        @click="navigateTo(item.url)"
                    >
                        <div class="flex items-center gap-2.5 truncate">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-foreground/80">
                                <Users class="size-4" />
                            </div>
                            <div class="truncate">
                                <div class="truncate font-medium leading-tight">{{ item.title }}</div>
                                <div class="truncate text-xs text-muted-foreground">{{ item.subtitle }}</div>
                            </div>
                        </div>
                        <ArrowRight class="size-3.5 shrink-0 text-muted-foreground" />
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="!isLoading && searchQuery.trim() && !hasResults" class="py-6 text-center text-sm text-muted-foreground">
                    <p>
                        No results found for "<span class="font-medium text-foreground">{{ searchQuery }}</span
                        >"
                    </p>
                    <div class="mt-3 flex justify-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md bg-muted px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted/80"
                            @click="handleSubmit"
                        >
                            Search in Products <ArrowRight class="size-3" />
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer hotkeys hint -->
            <div class="flex items-center justify-between border-t border-border/60 bg-muted/30 px-3 py-1.5 text-[11px] text-muted-foreground">
                <div class="flex items-center gap-2">
                    <span><kbd class="font-mono">↑</kbd><kbd class="font-mono">↓</kbd> navigate</span>
                    <span><kbd class="font-mono">↵</kbd> select</span>
                    <span><kbd class="font-mono">esc</kbd> close</span>
                </div>
                <span>POS Retail Global Search</span>
            </div>
        </div>
    </div>
</template>
