<script setup lang="ts">
import FlashToast from '@/components/FlashToast.vue';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

/*
 * Re-keying on the path recreates the wrapper on every navigation, which
 * replays the CSS entrance animation. Cheaper and steadier than a <Transition>
 * pair, and it never leaves two pages mounted at once.
 *
 * Query-string changes (search, filters, pagination) deliberately do NOT
 * re-key — re-animating the page on every keystroke would be nauseating.
 */
const page = usePage();
const pageKey = computed(() => new URL(page.url, 'http://x').pathname);
</script>

<template>
    <AppSidebarLayout :breadcrumbs="breadcrumbs">
        <div :key="pageKey" class="animate-rise">
            <slot />
        </div>
    </AppSidebarLayout>

    <FlashToast />
</template>
