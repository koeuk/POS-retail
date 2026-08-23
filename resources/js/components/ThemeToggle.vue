<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

withDefaults(defineProps<{ class?: string }>(), { class: '' });

const { appearance, updateAppearance } = useAppearance();

/*
 * Three states, not two. "System" is the default and the one most people
 * actually want — a shop tablet that dims itself in the evening — so a plain
 * light/dark switch would quietly throw it away the first time it was
 * pressed, with no way back except the settings page.
 */
const ORDER = ['system', 'light', 'dark'] as const;

const icon = computed(() => ({ system: Monitor, light: Sun, dark: Moon })[appearance.value]);

const label = computed(() => ({ system: 'Match the device', light: 'Light', dark: 'Dark' })[appearance.value]);

function cycle() {
    const next = ORDER[(ORDER.indexOf(appearance.value) + 1) % ORDER.length];
    updateAppearance(next);
}
</script>

<template>
    <button
        type="button"
        class="press flex size-10 items-center justify-center rounded-full transition-colors"
        :class="$props.class"
        :title="`Theme: ${label}. Tap to change.`"
        :aria-label="`Theme: ${label}. Tap to change.`"
        @click="cycle"
    >
        <component :is="icon" class="size-4" />
    </button>
</template>
