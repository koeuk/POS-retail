<script setup lang="ts">
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavGroup, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Boxes,
    LayoutGrid,
    ScanBarcode,
    Shapes,
    Store,
    Users,
    UsersRound,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const can = computed(() => page.props.auth.can);
const currentPath = computed(() => new URL(page.url, 'http://x').pathname);

const groups: NavGroup[] = [
    {
        label: 'Selling',
        items: [
            { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
            { title: 'Point of Sale', href: '/pos', icon: ScanBarcode },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            { title: 'Products', href: '/products', icon: Boxes, requires: 'manage' },
            { title: 'Categories', href: '/categories', icon: Shapes, requires: 'manage' },
        ],
    },
    {
        label: 'People',
        items: [
            { title: 'Customers', href: '/customers', icon: UsersRound, requires: 'manage' },
            { title: 'Staff', href: '/users', icon: Users, requires: 'isAdmin' },
            { title: 'Stores', href: '/stores', icon: Store, requires: 'manage' },
        ],
    },
];

/** Drop whole groups the user cannot see anything in. */
const visibleGroups = computed(() =>
    groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => !item.requires || can.value[item.requires]),
        }))
        .filter((group) => group.items.length > 0),
);

const isActive = (href: string) =>
    currentPath.value === href || currentPath.value.startsWith(`${href}/`);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup v-for="group in visibleGroups" :key="group.label">
                <SidebarGroupLabel class="font-mono text-[0.65rem] uppercase tracking-[0.14em]">
                    {{ group.label }}
                </SidebarGroupLabel>
                <SidebarGroupContent>
                    <SidebarMenu>
                        <SidebarMenuItem v-for="item in group.items" :key="item.href">
                            <SidebarMenuButton
                                as-child
                                :is-active="isActive(item.href)"
                                :tooltip="item.title"
                                class="press"
                            >
                                <Link :href="item.href">
                                    <component :is="item.icon" />
                                    <span>{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroupContent>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
