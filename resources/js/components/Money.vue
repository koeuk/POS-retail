<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Decimals arrive from Laravel as strings to avoid float drift. */
        value: string | number | null | undefined;
        symbol?: string;
        /** Dim the symbol so columns of figures read as one block. */
        muted?: boolean;
    }>(),
    { symbol: '$', muted: true },
);

const amount = computed(() => {
    const n = Number(props.value ?? 0);
    return Number.isFinite(n) ? n : 0;
});

const formatted = computed(() =>
    amount.value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }),
);
</script>

<template>
    <span class="tabular whitespace-nowrap font-mono text-[0.9em]">
        <span :class="muted ? 'text-muted-foreground' : ''">{{ symbol }}</span
        >{{ formatted }}
    </span>
</template>
