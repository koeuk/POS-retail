<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Shapes, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    categories: Category[];
    filters: { search?: string };
}>();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('categories.index'), { search: search.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const editing = ref<Category | null>(null);
const dialogOpen = ref(false);

const form = useForm({ name: '' });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(category: Category) {
    editing.value = category;
    form.clearErrors();
    form.name = category.name;
    dialogOpen.value = true;
}

function submit() {
    const payload = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    if (editing.value) {
        form.put(route('categories.update', { category: editing.value.id }), payload);
    } else {
        form.post(route('categories.store'), payload);
    }
}

const pendingDelete = ref<Category | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('categories.destroy', { category: pendingDelete.value.id }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}
</script>

<template>
    <Head title="Categories" />

    <AppLayout :breadcrumbs="[{ title: 'Categories', href: '/categories' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader eyebrow="Catalogue" title="Categories" description="Used to group products and filter the POS grid.">
                <template #actions>
                    <Button class="press" @click="openCreate">
                        <Plus class="size-4" />
                        New category
                    </Button>
                </template>
            </PageHeader>

            <div class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 60ms">
                <div class="border-b border-border p-3">
                    <div class="relative max-w-sm">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search categories…" class="pl-9" autocomplete="off" />
                    </div>
                </div>

                <ul v-if="categories.length" class="divide-y divide-border">
                    <li v-for="c in categories" :key="c.id" class="group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40">
                        <Shapes class="size-4 shrink-0 text-primary" />

                        <p class="min-w-0 flex-1 truncate font-medium leading-tight">{{ c.name }}</p>

                        <Badge variant="outline" class="tabular font-mono">
                            {{ c.products_count ?? 0 }} product{{ c.products_count === 1 ? '' : 's' }}
                        </Badge>

                        <div class="flex items-center gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                            <Button variant="ghost" size="icon" class="press size-8" aria-label="Edit" @click="openEdit(c)">
                                <Pencil class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="press size-8 text-muted-foreground hover:text-destructive"
                                aria-label="Delete"
                                @click="pendingDelete = c"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="Shapes"
                    title="No categories yet"
                    description="Categories group products so cashiers can find them quickly."
                >
                    <Button variant="outline" class="press" @click="openCreate">Add a category</Button>
                </EmptyState>
            </div>
        </div>

        <!-- Create / edit -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit category' : 'New category' }}</DialogTitle>
                        <DialogDescription> Categories group products so cashiers can find them quickly. </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="cat-name">Name</Label>
                            <Input id="cat-name" v-model="form.name" required autofocus />
                            <InputError :message="form.errors.name" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="dialogOpen = false"> Cancel </Button>
                        <Button type="submit" class="press" :disabled="form.processing">
                            {{ editing ? 'Save' : 'Create' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete -->
        <Dialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete “{{ pendingDelete?.name }}”?</DialogTitle>
                    <DialogDescription> A category that still holds products cannot be deleted. </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete"> Delete </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
