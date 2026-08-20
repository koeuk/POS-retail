<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import Money from '@/components/Money.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category, Paginated, Product } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Boxes, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    products: Paginated<Product>;
    categories: Category[];
    filters: { search?: string; category_id?: string; status?: string };
}>();

const search = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.category_id ?? 'all');
const status = ref(props.filters.status ?? 'all');

const ALL = 'all';
let debounce: ReturnType<typeof setTimeout>;

function applyFilters() {
    router.get(
        route('products.index'),
        {
            search: search.value || undefined,
            category_id: categoryId.value === ALL ? undefined : categoryId.value,
            status: status.value === ALL ? undefined : status.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(applyFilters, 300);
});

watch([categoryId, status], applyFilters);

const pendingDelete = ref<Product | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('products.destroy', { product: pendingDelete.value.id }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}

/** Stock is signed — offline sales can legitimately drive it below zero. */
function stockTone(qty: number | null | undefined) {
    const n = qty ?? 0;
    if (n < 0) return 'text-destructive font-semibold';
    if (n === 0) return 'text-muted-foreground';
    if (n <= 10) return 'text-primary';
    return '';
}
</script>

<template>
    <Head title="Products" />

    <AppLayout :breadcrumbs="[{ title: 'Products', href: '/products' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader eyebrow="Catalogue" title="Products" description="Everything you sell, with live stock across all stores.">
                <template #actions>
                    <Button as-child class="press">
                        <Link :href="route('products.create')">
                            <Plus class="size-4" />
                            New product
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <!-- Filters -->
                <div class="grid grid-cols-2 gap-2 border-b border-border p-3 md:flex md:flex-wrap md:items-center">
                    <div class="relative col-span-2 md:min-w-[14rem] md:flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name, SKU or barcode…" class="pl-9" autocomplete="off" />
                    </div>

                    <Select v-model="categoryId">
                        <SelectTrigger class="w-full md:w-[11rem]">
                            <SelectValue placeholder="Category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All categories</SelectItem>
                            <SelectItem v-for="c in categories" :key="c.id" :value="String(c.id)">
                                {{ c.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="status">
                        <SelectTrigger class="w-full md:w-[9rem]">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Table -->
                <div v-if="products.data.length" class="hidden overflow-x-auto md:block">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="w-[40%]">Product</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead data-numeric class="text-right">Cost</TableHead>
                                <TableHead data-numeric class="text-right">Price</TableHead>
                                <TableHead data-numeric class="text-right">Stock</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="w-[1%]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TransitionGroup
                            tag="tbody"
                            enter-from-class="opacity-0"
                            enter-active-class="transition-opacity duration-200"
                            leave-to-class="opacity-0"
                            leave-active-class="transition-opacity duration-150"
                            class="[&_tr:last-child]:border-0"
                        >
                            <TableRow v-for="p in products.data" :key="p.id" class="group">
                                <TableCell>
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted/50"
                                        >
                                            <img v-if="p.image" :src="`/storage/${p.image}`" :alt="p.name" class="size-full object-cover" />
                                            <Boxes v-else class="size-4 text-muted-foreground" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium leading-tight">{{ p.name }}</p>
                                            <p class="tabular truncate font-mono text-xs text-muted-foreground">
                                                {{ p.sku }}<span v-if="p.barcode"> · {{ p.barcode }}</span>
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell class="text-muted-foreground">
                                    {{ p.category?.name ?? '—' }}
                                </TableCell>
                                <TableCell data-numeric class="text-right text-muted-foreground">
                                    <Money :value="p.cost_price" />
                                </TableCell>
                                <TableCell data-numeric class="text-right font-medium">
                                    <Money :value="p.sell_price" />
                                </TableCell>
                                <TableCell data-numeric class="text-right">
                                    <span class="tabular font-mono text-sm" :class="stockTone(p.stock_qty)">
                                        {{ p.stock_qty ?? 0 }}
                                    </span>
                                    <span class="ml-1 text-xs text-muted-foreground">{{ p.unit }}</span>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="p.is_active ? 'secondary' : 'outline'">
                                        {{ p.is_active ? 'Active' : 'Inactive' }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <div
                                        class="flex items-center gap-1 transition-opacity focus-within:opacity-100 group-hover:opacity-100 [@media(hover:hover)]:opacity-0"
                                    >
                                        <Button as-child variant="ghost" size="icon" class="press size-8">
                                            <Link :href="route('products.edit', { product: p.id })" aria-label="Edit">
                                                <Pencil class="size-4" />
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 text-muted-foreground hover:text-destructive"
                                            aria-label="Delete"
                                            @click="pendingDelete = p"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TransitionGroup>
                    </Table>
                </div>

                <!-- Phone list. Tap the row to edit; the trailing control deletes. -->
                <ul v-if="products.data.length" class="md:hidden">
                    <li v-for="p in products.data" :key="p.id" class="list-row">
                        <Link :href="route('products.edit', { product: p.id })" class="list-row-main">
                            <div
                                class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted/50"
                            >
                                <img v-if="p.image" :src="`/storage/${p.image}`" :alt="p.name" class="size-full object-cover" />
                                <Boxes v-else class="size-5 text-muted-foreground" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium leading-tight">{{ p.name }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    <span class="tabular font-mono">{{ p.sku }}</span>
                                    <span v-if="p.category"> · {{ p.category.name }}</span>
                                    <span v-if="!p.is_active" class="text-destructive"> · Inactive</span>
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="font-medium leading-tight"><Money :value="p.sell_price" /></p>
                                <p class="tabular font-mono text-xs" :class="stockTone(p.stock_qty)">{{ p.stock_qty ?? 0 }} {{ p.unit }}</p>
                            </div>
                        </Link>

                        <button type="button" class="list-row-action" :aria-label="`Delete ${p.name}`" @click="pendingDelete = p">
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="Boxes"
                    title="No products found"
                    description="Try clearing the filters, or add your first product to the catalogue."
                >
                    <Button as-child variant="outline" class="press">
                        <Link :href="route('products.create')">Add a product</Link>
                    </Button>
                </EmptyState>

                <Pagination :links="products.links" :from="products.from" :to="products.to" :total="products.total" />
            </div>
        </div>

        <AlertDialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete “{{ pendingDelete?.name }}”?</AlertDialogTitle>
                    <AlertDialogDescription>
                        If this product has any sales history it will be deactivated instead of deleted, so past receipts keep working.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction class="bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete">
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
