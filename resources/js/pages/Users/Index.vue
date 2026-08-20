<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated, SharedData, Store, User } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, Users } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    users: Paginated<User & { store?: Pick<Store, 'id' | 'name'> | null }>;
    stores: Store[];
    roles: { value: string; label: string }[];
    filters: { search?: string; role?: string };
}>();

const page = usePage<SharedData>();
const currentUserId = computed(() => page.props.auth.user?.id);

const ALL = 'all';
const NONE = 'none';

const search = ref(props.filters.search ?? '');
const roleFilter = ref(props.filters.role ?? ALL);
let debounce: ReturnType<typeof setTimeout>;

function reload() {
    router.get(
        route('users.index'),
        {
            search: search.value || undefined,
            role: roleFilter.value === ALL ? undefined : roleFilter.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(reload, 300);
});
watch(roleFilter, reload);

const editing = ref<User | null>(null);
const dialogOpen = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'cashier',
    store_id: NONE,
    is_active: true,
});

/** A cashier cannot open /pos without a store, so the field becomes required. */
const storeRequired = computed(() => form.role === 'cashier');

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.store_id = NONE;
    dialogOpen.value = true;
}

function openEdit(user: User) {
    editing.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.password_confirmation = '';
    form.role = user.role;
    form.store_id = user.store_id ? String(user.store_id) : NONE;
    form.is_active = user.is_active;
    dialogOpen.value = true;
}

function submit() {
    const opts = { onSuccess: () => (dialogOpen.value = false), preserveScroll: true };

    form.transform((d) => ({ ...d, store_id: d.store_id === NONE ? null : d.store_id }));

    if (editing.value) {
        form.put(route('users.update', { user: editing.value.id }), opts);
    } else {
        form.post(route('users.store'), opts);
    }
}

const pendingDelete = ref<User | null>(null);

function confirmDelete() {
    if (!pendingDelete.value) return;
    router.delete(route('users.destroy', { user: pendingDelete.value.id }), {
        preserveScroll: true,
        onFinish: () => (pendingDelete.value = null),
    });
}

const roleTone = (role: string) =>
    role === 'admin' ? 'default' : role === 'manager' ? 'secondary' : 'outline';
</script>

<template>
    <Head title="Staff" />

    <AppLayout :breadcrumbs="[{ title: 'Staff', href: '/users' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                eyebrow="People"
                title="Staff"
                description="Accounts are created here — there is no public sign-up. Everyone signs in on the same login page."
            >
                <template #actions>
                    <Button class="press" @click="openCreate">
                        <Plus class="size-4" />
                        New staff
                    </Button>
                </template>
            </PageHeader>

            <div class="animate-rise rounded-xl border border-border bg-card shadow-sm" style="animation-delay: 60ms">
                <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <div class="relative min-w-[14rem] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="search" placeholder="Search name or email…" class="pl-9" autocomplete="off" />
                    </div>
                    <Select v-model="roleFilter">
                        <SelectTrigger class="w-[11rem]">
                            <SelectValue placeholder="Role" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">All roles</SelectItem>
                            <SelectItem v-for="r in roles" :key="r.value" :value="r.value">
                                {{ r.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="users.data.length" class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Store</TableHead>
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
                            <TableRow v-for="u in users.data" :key="u.id" class="group">
                                <TableCell>
                                    <p class="font-medium leading-tight">
                                        {{ u.name }}
                                        <span v-if="u.id === currentUserId" class="ml-1 text-xs text-muted-foreground">(you)</span>
                                    </p>
                                    <p class="text-xs text-muted-foreground">{{ u.email }}</p>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="roleTone(u.role)" class="capitalize">{{ u.role }}</Badge>
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{ u.store?.name ?? 'All stores' }}
                                </TableCell>
                                <TableCell>
                                    <span
                                        class="inline-flex items-center gap-1.5 text-sm"
                                        :class="u.is_active ? '' : 'text-muted-foreground'"
                                    >
                                        <span
                                            class="size-1.5 rounded-full"
                                            :class="u.is_active ? 'bg-primary' : 'bg-muted-foreground/40'"
                                        />
                                        {{ u.is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                                        <Button variant="ghost" size="icon" class="press size-8" aria-label="Edit" @click="openEdit(u)">
                                            <Pencil class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="u.id !== currentUserId"
                                            variant="ghost"
                                            size="icon"
                                            class="press size-8 text-muted-foreground hover:text-destructive"
                                            aria-label="Delete"
                                            @click="pendingDelete = u"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TransitionGroup>
                    </Table>
                </div>

                <EmptyState v-else :icon="Users" title="No staff found" description="Try clearing the filters." />

                <Pagination :links="users.links" :from="users.from" :to="users.to" :total="users.total" />
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-w-lg">
                <form @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{ editing ? 'Edit staff account' : 'New staff account' }}</DialogTitle>
                        <DialogDescription>
                            <span v-if="editing">Leave the password blank to keep the current one.</span>
                            <span v-else>They sign in at the same login page as everyone else.</span>
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="u-name">Name</Label>
                                <Input id="u-name" v-model="form.name" required autofocus />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="u-email">Email</Label>
                                <Input id="u-email" v-model="form.email" type="email" required />
                                <InputError :message="form.errors.email" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="u-role">Role</Label>
                                <Select v-model="form.role" :disabled="editing?.id === currentUserId">
                                    <SelectTrigger id="u-role">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="r in roles" :key="r.value" :value="r.value">
                                            {{ r.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors.role" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="u-store">
                                    Store
                                    <span v-if="storeRequired" class="text-destructive">*</span>
                                </Label>
                                <Select v-model="form.store_id">
                                    <SelectTrigger id="u-store">
                                        <SelectValue placeholder="All stores" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="NONE" :disabled="storeRequired">All stores</SelectItem>
                                        <SelectItem v-for="s in stores" :key="s.id" :value="String(s.id)">
                                            {{ s.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="storeRequired" class="text-xs text-muted-foreground">
                                    A cashier must have a store — the POS reads stock from it.
                                </p>
                                <InputError :message="form.errors.store_id" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="u-pass">Password</Label>
                                <Input id="u-pass" v-model="form.password" type="password" :required="!editing" autocomplete="new-password" />
                                <InputError :message="form.errors.password" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="u-pass2">Confirm password</Label>
                                <Input id="u-pass2" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                            <div>
                                <p class="text-sm font-medium">Active</p>
                                <p class="text-xs text-muted-foreground">
                                    Disabling locks them out on their very next request
                                </p>
                            </div>
                            <Switch v-model="form.is_active" :disabled="editing?.id === currentUserId" />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" class="press" @click="dialogOpen = false">Cancel</Button>
                        <Button type="submit" class="press" :disabled="form.processing">
                            {{ editing ? 'Save' : 'Create' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="!!pendingDelete" @update:open="(v) => !v && (pendingDelete = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ pendingDelete?.name }}?</DialogTitle>
                    <DialogDescription>
                        If they have rung up any sales the account is deactivated instead, so the
                        order history keeps its cashier.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" class="press" @click="pendingDelete = null">Cancel</Button>
                    <Button
                        class="press bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        @click="confirmDelete"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
