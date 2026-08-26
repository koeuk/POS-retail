<script setup lang="ts">
import type { PaginationLink } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
    /** Rows per page the server actually used. */
    perPage?: number;
}>();

/**
 * Must match App\Support\PerPage::OPTIONS. The server whitelists the value
 * rather than clamping it, so a size missing from this list would be silently
 * ignored — keep the two in step.
 */
const PER_PAGE_OPTIONS = [10, 20, 50, 100, 150, 200];

const page = usePage();
const perPage = computed(() => props.perPage ?? 20);

/*
 * Changing the page size restarts at page 1: keeping `page=3` while shrinking
 * to 10 rows could land past the end of a short list and show nothing.
 * Every other filter in the query string is kept.
 */
function changePerPage(event: Event) {
    const value = Number((event.target as HTMLSelectElement).value);
    const url = new URL(page.url, window.location.origin);

    url.searchParams.set('per_page', String(value));
    url.searchParams.delete('page');

    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true, replace: true });
}

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
        <div class="flex items-center gap-3">
            <p class="font-mono text-xs text-muted-foreground">
                <span class="tabular">{{ from ?? 0 }}–{{ to ?? 0 }}</span>
                of
                <span class="tabular">{{ total }}</span>
            </p>

            <!--
                Only shown once the list is longer than the smallest page size;
                a selector on a 7-row list is a control with nothing to do.
            -->
            <label v-if="total > PER_PAGE_OPTIONS[0]" class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span class="hidden sm:inline">Show</span>
                <select
                    :value="perPage"
                    class="tabular h-8 rounded-md border border-input bg-background px-2 font-mono text-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    aria-label="Rows per page"
                    @change="changePerPage"
                >
                    <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
                </select>
            </label>
        </div>

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
