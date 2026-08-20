<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue';
import ProductForm from '@/components/ProductForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Category } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{ categories: Category[]; defaultTaxRate: number }>();
</script>

<template>
    <Head title="New product" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Products', href: '/products' },
            { title: 'New', href: '/products/create' },
        ]"
    >
        <div class="px-5 py-6 md:px-8">
            <PageHeader
                eyebrow="Catalogue"
                title="New product"
                description="Add an item to the catalogue. It appears on the POS grid as soon as it is active."
            />

            <ProductForm :categories="categories" :default-tax-rate="defaultTaxRate" />
        </div>
    </AppLayout>
</template>
