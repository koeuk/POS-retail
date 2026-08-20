<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import MobileAppBar from '@/components/MobileAppBar.vue';
import MobileTabBar from '@/components/MobileTabBar.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <!--
            The sidebar stays mounted on a phone but renders nothing there
            (`hidden md:flex` on the Sidebar itself). Unmounting it instead
            would tear down the sidebar context that AppContent's inset reads
            from, and the layout would collapse at the breakpoint.
        -->
        <AppSidebar />

        <AppContent variant="sidebar">
            <!-- One header per breakpoint: breadcrumbs on desktop, app bar on phone. -->
            <AppSidebarHeader :breadcrumbs="breadcrumbs" class="hidden md:flex" />
            <MobileAppBar :breadcrumbs="breadcrumbs" />

            <!-- Content clears the fixed tab bar; the desktop has no tab bar to clear. -->
            <div class="pb-tabbar md:pb-0">
                <slot />
            </div>
        </AppContent>

        <MobileTabBar />
    </AppShell>
</template>
