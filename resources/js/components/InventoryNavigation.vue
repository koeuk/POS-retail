<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { inventoryItems } from '@/lib/navigation';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const { canAccess } = usePermissions();
const items = computed(() => inventoryItems.filter((item) => !item.requires || canAccess(item.requires)));
const path = computed(() => new URL(page.url, 'http://x').pathname);
const active = (href: string) => path.value === href || path.value.startsWith(`${href}/`);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
        <nav
            aria-label="Inventory sections"
            class="isolate flex max-w-full items-stretch overflow-x-auto rounded-full border border-border bg-card shadow-sm"
        >
            <Link
                v-for="item in items"
                :key="item.href"
                :href="item.href"
                :aria-current="active(item.href) ? 'page' : undefined"
                class="relative flex shrink-0 items-center justify-center gap-2 border-r border-border px-4 py-2.5 text-sm font-medium transition-colors first:rounded-l-full last:rounded-r-full last:border-r-0 focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                :class="active(item.href) ? 'bg-brand text-brand-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'"
            >
                <component :is="item.icon" class="size-4" />
                {{ item.title }}
            </Link>
        </nav>
        <div v-if="$slots.actions" class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
