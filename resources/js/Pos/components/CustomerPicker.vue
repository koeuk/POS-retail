<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { http } from '@/Pos/lib/http';
import { Plus, Search, UserRound } from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Customer {
    id: number;
    name: string;
    phone: string | null;
}

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: []; pick: [customer: Customer] }>();

const query = ref('');
const results = ref<Customer[]>([]);
const loading = ref(false);
const failed = ref(false);
let debounce: ReturnType<typeof setTimeout>;

/*
 * Debt needs a name to collect from, and a shop often has no customer list
 * at all when the first debt happens. So this is search-or-create in one
 * box: type a name, and if nobody matches, the same name becomes a new
 * customer with one tap. Turning a debt away for lack of a record is worse
 * than a slightly untidy customer list.
 */
async function search() {
    loading.value = true;
    failed.value = false;
    try {
        const { data } = await http.get<Customer[]>('/customers', { params: { q: query.value } });
        results.value = data;
    } catch {
        failed.value = true;
        results.value = [];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        query.value = '';
        creating.value = false;
        void search();
    },
);
watch(query, () => {
    clearTimeout(debounce);
    debounce = setTimeout(search, 250);
});

/* Inline create. */
const creating = ref(false);
const newPhone = ref('');
const createError = ref<string | null>(null);
const saving = ref(false);

async function create() {
    createError.value = null;
    saving.value = true;
    try {
        const { data } = await http.post<Customer>('/customers', { name: query.value.trim(), phone: newPhone.value.trim() || null });
        emit('pick', data);
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
        createError.value = msg ?? 'Could not save the customer.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('close')">
        <DialogContent class="max-w-sm">
            <DialogHeader>
                <DialogTitle>Who owes this?</DialogTitle>
                <DialogDescription>A debt has to have a name on it, or there is nobody to collect from.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 py-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="query" placeholder="Name or phone…" class="pl-9" autofocus autocomplete="off" />
                </div>

                <p v-if="failed" class="text-xs text-destructive">Could not load customers — check the connection.</p>

                <ul v-else-if="results.length" class="max-h-56 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="c in results" :key="c.id">
                        <button
                            type="button"
                            class="press flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent"
                            @click="emit('pick', c)"
                        >
                            <UserRound class="size-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ c.name }}</span>
                                <span v-if="c.phone" class="tabular block font-mono text-xs text-muted-foreground">{{ c.phone }}</span>
                            </span>
                        </button>
                    </li>
                </ul>

                <p v-else-if="!loading" class="px-1 text-sm text-muted-foreground">
                    {{ query.trim() ? `No customer called “${query.trim()}”.` : 'No customers yet.' }}
                </p>

                <!-- Create the typed name as a new customer. -->
                <div v-if="query.trim().length >= 2" class="rounded-lg border border-dashed border-border p-3">
                    <button
                        v-if="!creating"
                        type="button"
                        class="press flex w-full items-center gap-2 text-left text-sm font-medium text-primary"
                        @click="creating = true"
                    >
                        <Plus class="size-4" />
                        Add “{{ query.trim() }}” as a new customer
                    </button>
                    <div v-else class="grid gap-2">
                        <Label for="np" class="text-xs text-muted-foreground">Phone (optional, helps you find them later)</Label>
                        <Input id="np" v-model="newPhone" inputmode="tel" class="font-mono" placeholder="012 345 678" />
                        <InputError :message="createError ?? undefined" />
                        <Button type="button" class="press" :disabled="saving" @click="create">Save &amp; use “{{ query.trim() }}”</Button>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" class="press" @click="emit('close')">Cancel</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
