<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import InventoryNavigation from '@/components/InventoryNavigation.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Unit } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Ruler, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    units: Unit[];
}>();

/* Controls the user cannot use are hidden; the policies are the wall. */
const { may } = usePermissions();

const search = ref('');
const filteredUnits = computed(() => props.units.filter((u) => `${u.name} ${u.symbol}`.toLowerCase().includes(search.value.trim().toLowerCase())));

const editing = ref<Unit | null>(null);
const dialogOpen = ref(false);

const form = useForm({ name: '', symbol: '' });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(unit: Unit) {
    editing.value = unit;
    form.clearErrors();
    form.name = unit.name;
    form.symbol = unit.symbol;
    dialogOpen.value = true;
}

function submit() {
    const payload = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    if (editing.value) {
        form.put(route('units.update', { unit: editing.value.uuid }), payload);
    } else {
        form.post(route('units.store'), payload);
    }
}

const pendingDelete = ref<Unit | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('units.destroy', { unit: pendingDelete.value.uuid }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}
</script>

<template>
    <Head title="Units" />

    <AppLayout :breadcrumbs="[{ title: 'Units', href: '/units' }]">
        <div class="px-2.5 py-6 md:px-8">
            <InventoryNavigation>
                <template #actions>
                    <Button v-if="may('units', 'create')" class="press hidden md:inline-flex" @click="openCreate">
                        <Plus class="size-4" />
                        New unit
                    </Button>
                </template>
            </InventoryNavigation>

            <PageHeader
                title="Units"
                description="Define the units used to count stock and sell packs. Conversion quantities belong to each product."
            />

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <div class="border-b border-border p-3">
                    <div class="relative max-w-sm">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search units…" class="h-10 rounded-full pl-9" autocomplete="off" />
                    </div>
                </div>

                <ul v-if="filteredUnits.length">
                    <li v-for="c in filteredUnits" :key="c.id" class="list-row group">
                        <!-- The row itself is the edit affordance — on a phone a
                             pencil hiding behind a hover state is unreachable. -->
                        <button
                            type="button"
                            class="list-row-main md:px-4"
                            :disabled="!may('units', 'update')"
                            :aria-label="may('units', 'update') ? `Edit ${c.name}` : c.name"
                            @click="openEdit(c)"
                        >
                            <Ruler class="size-4 shrink-0 text-primary" />

                            <p class="min-w-0 flex-1 truncate font-medium leading-tight">
                                {{ c.name }} <span class="ml-2 font-mono text-xs text-muted-foreground">{{ c.symbol }}</span>
                            </p>

                            <Badge variant="outline" class="tabular shrink-0 font-mono">
                                {{ c.products_count ?? 0 }} product{{ c.products_count === 1 ? '' : 's' }}
                            </Badge>

                            <Pencil class="hidden size-4 shrink-0 text-muted-foreground md:block" />
                        </button>

                        <div class="flex shrink-0 items-center"></div>

                        <button
                            v-if="may('units', 'delete')"
                            type="button"
                            class="list-row-action"
                            :aria-label="`Delete ${c.name}`"
                            @click="pendingDelete = c"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="Ruler"
                    title="No matching units"
                    description="Choose clear names and symbols, such as Bottle (bottle) or Gram (g)."
                >
                    <Button v-if="may('units', 'create')" variant="outline" class="press" @click="openCreate">Add a unit</Button>
                </EmptyState>
            </div>
        </div>

        <!-- Phone: create floats bottom-right above the tab bar, in thumb reach. -->
        <Button
            v-if="may('units', 'create')"
            class="press fixed right-4 z-40 h-12 rounded-full px-5 shadow-lg md:hidden"
            style="bottom: calc(var(--tabbar-h) + var(--safe-bottom) + 1rem)"
            @click="openCreate"
        >
            <Plus class="size-5" />
            New unit
        </Button>

        <!-- Create / edit -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit unit' : 'New unit' }}</DialogTitle>
                        <DialogDescription> Choose clear names and symbols, such as Bottle (bottle) or Gram (g). </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-2">
                            <Label for="unit-name">Name</Label>
                            <Input id="unit-name" v-model="form.name" required autofocus />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="unit-symbol">Symbol</Label>
                            <Input
                                id="unit-symbol"
                                v-model="form.symbol"
                                required
                                maxlength="20"
                                placeholder="bottle"
                                :disabled="!!editing?.products_count"
                            />
                            <p class="text-xs text-muted-foreground">Symbols on existing products stay fixed to protect stock records.</p>
                            <InputError :message="form.errors.symbol" />
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
                    <DialogDescription> A unit used by products cannot be deleted. </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button class="press bg-destructive text-destructive-foreground hover:bg-destructive/90" @click="confirmDelete"> Delete </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
