<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CornerDownRight, Pencil, Plus, Search, Shapes, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    categories: Category[];
    filters: { search?: string };
}>();

const NONE = 'none';
const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout>;

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(route('categories.index'), { search: search.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

/** Roots first, each followed by its children — a flat list that reads as a tree. */
const ordered = computed(() => {
    const roots = props.categories.filter((c) => !c.parent_id);
    const childrenOf = (id: number) => props.categories.filter((c) => c.parent_id === id);
    const out: Array<Category & { depth: number }> = [];

    for (const root of roots) {
        out.push({ ...root, depth: 0 });
        for (const child of childrenOf(root.id)) out.push({ ...child, depth: 1 });
    }

    // Anything whose parent fell outside the current filter still shows.
    for (const c of props.categories) {
        if (!out.some((o) => o.id === c.id)) out.push({ ...c, depth: 0 });
    }

    return out;
});

const editing = ref<Category | null>(null);
const dialogOpen = ref(false);

const form = useForm({ name: '', parent_id: NONE });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.parent_id = NONE;
    dialogOpen.value = true;
}

function openEdit(category: Category) {
    editing.value = category;
    form.clearErrors();
    form.name = category.name;
    form.parent_id = category.parent_id ? String(category.parent_id) : NONE;
    dialogOpen.value = true;
}

function submit() {
    const payload = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    form.transform((d) => ({ ...d, parent_id: d.parent_id === NONE ? null : d.parent_id }));

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

/** A category may not be reparented into itself. */
const parentOptions = computed(() => props.categories.filter((c) => c.id !== editing.value?.id && !c.parent_id));
</script>

<template>
    <Head title="Categories" />

    <AppLayout :breadcrumbs="[{ title: 'Categories', href: '/categories' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                eyebrow="Catalogue"
                title="Categories"
                description="Two levels deep — a parent and its children. Used to filter the POS grid."
            >
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

                <ul v-if="ordered.length" class="divide-y divide-border">
                    <li
                        v-for="c in ordered"
                        :key="c.id"
                        class="group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
                        :class="c.depth ? 'pl-10' : ''"
                    >
                        <CornerDownRight v-if="c.depth" class="size-3.5 shrink-0 text-muted-foreground/60" />
                        <Shapes v-else class="size-4 shrink-0 text-primary" />

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium leading-tight" :class="c.depth ? 'text-sm' : ''">
                                {{ c.name }}
                            </p>
                            <p v-if="c.parent" class="truncate text-xs text-muted-foreground">in {{ c.parent.name }}</p>
                        </div>

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
                        <DialogDescription> Leave the parent empty to make this a top-level category. </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="cat-name">Name</Label>
                            <Input id="cat-name" v-model="form.name" required autofocus />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="cat-parent">Parent</Label>
                            <Select v-model="form.parent_id">
                                <SelectTrigger id="cat-parent">
                                    <SelectValue placeholder="Top level" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="NONE">Top level</SelectItem>
                                    <SelectItem v-for="p in parentOptions" :key="p.id" :value="String(p.id)">
                                        {{ p.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.parent_id" />
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
                    <DialogDescription>
                        Any sub-categories are promoted to top level. A category that still holds products cannot be deleted.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete"> Delete </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
