<script setup lang="ts">
import type { LucideIcon } from 'lucide-vue-next';
import { TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    label: string;
    value: string;
    icon?: LucideIcon;
    /** Prior period, for the change indicator. Omit to hide it. */
    previous?: string | number | null;
    /** Rendered under the value when there is no comparison to make. */
    hint?: string;
    tone?: 'default' | 'warning';
}>();

/*
 * A single headline number is a stat tile, not a chart — there is nothing to
 * compare within it, so a plot would be decoration.
 */
const delta = computed(() => {
    if (props.previous === null || props.previous === undefined) return null;

    const now = Number(props.value.replace(/[^0-9.-]/g, ''));
    const before = Number(props.previous);

    if (!Number.isFinite(now) || !Number.isFinite(before) || before === 0) return null;

    return ((now - before) / before) * 100;
});
</script>

<template>
    <article class="lift rounded-xl border bg-card p-4 shadow-sm" :class="tone === 'warning' ? 'border-destructive/40' : 'border-border'">
        <div class="flex items-center justify-between gap-2">
            <p class="font-mono text-[0.65rem] uppercase tracking-[0.14em] text-muted-foreground">
                {{ label }}
            </p>
            <component :is="icon" v-if="icon" class="size-4" :class="tone === 'warning' ? 'text-destructive' : 'text-primary'" />
        </div>

        <p class="tabular mt-2 font-mono text-2xl font-bold leading-none" :class="tone === 'warning' ? 'text-destructive' : ''">
            {{ value }}
        </p>

        <p v-if="delta !== null" class="mt-1.5 flex items-center gap-1 text-xs" :class="delta >= 0 ? 'text-primary' : 'text-muted-foreground'">
            <component :is="delta >= 0 ? TrendingUp : TrendingDown" class="size-3" />
            <span class="tabular font-mono">{{ Math.abs(delta).toFixed(0) }}%</span>
            <span class="text-muted-foreground">vs yesterday</span>
        </p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-muted-foreground">{{ hint }}</p>
    </article>
</template>
