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
        class="hidden md:flex h-9 items-center gap-2 rounded-md border border-input/80 bg-card/60 px-3 text-xs select-none tabular-nums text-muted-foreground shadow-xs"
        :title="`${formattedDate} ${formattedTime}`"
    >
        <Clock class="size-3.5 shrink-0 text-muted-foreground" />
        <span class="hidden lg:inline font-medium text-foreground">{{ formattedDate }}</span>
        <span class="hidden lg:inline text-muted-foreground/40">·</span>
        <span class="font-mono font-medium text-foreground">{{ formattedTime }}</span>
    </div>
</template>
