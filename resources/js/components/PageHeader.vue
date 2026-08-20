<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    eyebrow?: string;
}>();
</script>

<template>
    <header class="animate-rise flex flex-wrap items-end justify-between gap-4 pb-6">
        <div class="space-y-1.5">
            <p
                v-if="eyebrow"
                class="font-mono text-[0.65rem] uppercase tracking-[0.18em] text-primary"
            >
                {{ eyebrow }}
            </p>
            <h1 class="font-display text-3xl font-semibold leading-none tracking-tight">
                {{ title }}
            </h1>
            <p v-if="description" class="max-w-prose text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
