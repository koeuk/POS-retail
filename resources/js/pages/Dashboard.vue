<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Boxes, ScanBarcode, TrendingUp, UsersRound } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const can = computed(() => page.props.auth.can);

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
});

/* Real figures arrive in Phase 8 — these tiles hold their shape so the
   layout does not jump when the data lands. */
const tiles = [
    { label: "Today's sales", icon: TrendingUp },
    { label: 'Orders', icon: ScanBarcode },
    { label: 'Low stock', icon: Boxes },
    { label: 'Customers', icon: UsersRound },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="[{ title: 'Dashboard', href: '/dashboard' }]">
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                :eyebrow="greeting"
                :title="user?.name ?? 'Dashboard'"
                description="A summary of today across the shop floor."
            >
                <template #actions>
                    <Button as-child class="press">
                        <Link href="/pos">
                            <ScanBarcode class="size-4" />
                            Open POS
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="stagger grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="lift rounded-xl border border-border bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between">
                        <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">
                            {{ tile.label }}
                        </p>
                        <component :is="tile.icon" class="size-4 text-primary" />
                    </div>
                    <Skeleton class="mt-3 h-8 w-24" />
                    <Skeleton class="mt-2 h-3 w-32" />
                </article>
            </div>

            <div
                class="animate-rise mt-6 rounded-xl border border-dashed border-border bg-card/50 p-8 text-center"
                style="animation-delay: 200ms"
            >
                <p class="font-display text-lg font-semibold">Reports land in Phase 8</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Sales by day and by product, payment-method breakdown, and the oversold-stock
                    reconciliation list all arrive once the POS and sync layers are proven.
                </p>
                <div v-if="can.manage" class="mt-4 flex flex-wrap justify-center gap-2">
                    <Button as-child variant="outline" size="sm" class="press">
                        <Link :href="route('products.index')">Manage products</Link>
                    </Button>
                    <Button as-child variant="outline" size="sm" class="press">
                        <Link :href="route('customers.index')">Manage customers</Link>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
