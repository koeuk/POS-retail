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
        <nav aria-label="Inventory sections" class="flex max-w-full items-center gap-1 overflow-x-auto rounded-lg bg-muted p-1">
            <Link
                v-for="item in items"
                :key="item.href"
                :href="item.href"
                :aria-current="active(item.href) ? 'page' : undefined"
                class="flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    active(item.href) ? 'bg-background text-primary shadow-sm' : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
                "
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
