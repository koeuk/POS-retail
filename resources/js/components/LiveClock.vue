<script setup lang="ts">
import { Clock } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const formattedDate = computed(() =>
    now.value.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }),
);

const formattedTime = computed(() =>
    now.value.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }),
);
</script>

<template>
    <div
        class="hidden md:flex items-center gap-2 text-sm select-none tabular-nums text-primary"
        :title="`${formattedDate} ${formattedTime}`"
    >
        <Clock class="size-4 shrink-0 text-primary" />
        <span class="hidden lg:inline font-medium text-primary">{{ formattedDate }}</span>
        <span class="hidden lg:inline text-primary/40">·</span>
        <span class="font-mono font-semibold tracking-tight text-primary">{{ formattedTime }}</span>
    </div>
</template>
