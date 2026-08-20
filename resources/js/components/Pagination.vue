<script setup lang="ts">
import type { PaginationLink } from '@/types';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();

/** Laravel emits "&laquo; Previous" / "Next &raquo;" as raw entities. */
const clean = (label: string) => label.replace(/&laquo;|&raquo;/g, '').trim();

/*
 * Laravel always puts Previous first and Next last. A phone gets those two and
 * nothing else — a row of numbered page chips is a mouse affordance, and at
 * 360px it wraps onto three lines that are all too small to hit.
 */
const previous = computed(() => props.links[0]);
const next = computed(() => props.links[props.links.length - 1]);
const numbered = computed(() => props.links.slice(1, -1));
const hasPages = computed(() => numbered.value.length > 1);
</script>

<template>
    <div v-if="total > 0" class="flex items-center justify-between gap-3 border-t border-border px-4 py-3">
        <p class="font-mono text-xs text-muted-foreground">
            <span class="tabular">{{ from ?? 0 }}–{{ to ?? 0 }}</span>
            of
            <span class="tabular">{{ total }}</span>
        </p>

        <!-- Phone: two large targets. -->
        <nav v-if="hasPages" class="flex items-center gap-1.5 md:hidden">
            <component
                :is="previous.url ? Link : 'span'"
                :href="previous.url ?? undefined"
                preserve-scroll
                class="press flex h-10 min-w-10 items-center justify-center rounded-lg border border-border px-3"
                :class="previous.url ? '' : 'pointer-events-none opacity-40'"
                aria-label="Previous page"
            >
                <ChevronLeft class="size-4" />
            </component>
            <component
                :is="next.url ? Link : 'span'"
                :href="next.url ?? undefined"
                preserve-scroll
                class="press flex h-10 min-w-10 items-center justify-center rounded-lg border border-border px-3"
                :class="next.url ? '' : 'pointer-events-none opacity-40'"
                aria-label="Next page"
            >
                <ChevronRight class="size-4" />
            </component>
        </nav>

        <!-- Desktop: the full numbered run. -->
        <nav v-if="hasPages" class="hidden items-center gap-1 md:flex">
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
