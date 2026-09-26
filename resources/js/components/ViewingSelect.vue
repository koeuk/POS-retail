<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Eye } from 'lucide-vue-next';
import { computed } from 'vue';

/*
 * The admin's "Viewing" switcher — every screen follows it: all vendors, the
 * admin's own shop, or one vendor exactly as that vendor sees it. Renders
 * nothing for anyone else; they are always pinned to their own vendor.
 */
const page = usePage<SharedData>();
const viewing = computed(() => page.props.viewing);

function pick(value: unknown) {
    router.put(route('viewing.update'), { viewing: String(value) }, { preserveScroll: true });
}
</script>

<template>
    <Select v-if="viewing" :model-value="viewing.current" @update:model-value="pick">
        <SelectTrigger class="h-9 w-auto max-w-40 gap-2 text-sm sm:min-w-40 sm:max-w-none" aria-label="Viewing">
            <Eye class="size-4 shrink-0 text-muted-foreground" />
            <SelectValue />
        </SelectTrigger>
        <SelectContent>
            <SelectItem value="all">All vendors</SelectItem>
            <SelectItem value="shop">My shop</SelectItem>
            <SelectItem v-for="v in viewing.vendors" :key="v.id" :value="String(v.id)">{{ v.name }}</SelectItem>
        </SelectContent>
    </Select>
</template>
