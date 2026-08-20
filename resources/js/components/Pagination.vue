<script setup lang="ts">
import type { PaginationLink } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();

/** Laravel emits "&laquo; Previous" / "Next &raquo;" as raw entities. */
const clean = (label: string) => label.replace(/&laquo;|&raquo;/g, '').trim();
</script>

<template>
    <div v-if="total > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p class="font-mono text-xs text-muted-foreground">
            <span class="tabular">{{ from ?? 0 }}–{{ to ?? 0 }}</span>
            of
            <span class="tabular">{{ total }}</span>
        </p>

        <nav v-if="links.length > 3" class="flex items-center gap-1">
            <template v-for="(link, i) in links" :key="i">
                <span v-if="!link.url" class="px-2.5 py-1 text-xs text-muted-foreground/50">
                    {{ clean(link.label) }}
                </span>
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="press rounded-md px-2.5 py-1 font-mono text-xs transition-colors"
                    :class="link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'"
                >
                    {{ clean(link.label) }}
                </Link>
            </template>
        </nav>
    </div>
</template>
