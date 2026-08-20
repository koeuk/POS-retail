<script setup lang="ts">
import { CloudOff, LoaderCircle, LockKeyhole, RefreshCw, Wifi } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    online: boolean;
    syncing: boolean;
    pending: number;
    authExpired: boolean;
}>();

const emit = defineEmits<{ retry: [] }>();

/**
 * Four states, in order of how much they should worry the cashier:
 *
 *  - locked   session gone; selling still works, syncing does not
 *  - offline  no server; sales are queueing, which is fine
 *  - syncing  draining the queue right now
 *  - online   nothing outstanding
 */
const state = computed(() => {
    if (props.authExpired) return 'locked';
    if (!props.online) return 'offline';
    if (props.syncing) return 'syncing';
    return 'online';
});

const label = computed(() => {
    switch (state.value) {
        case 'locked':
            return 'Sign in to sync';
        case 'offline':
            return props.pending > 0 ? `Offline · ${props.pending} queued` : 'Offline';
        case 'syncing':
            return 'Syncing…';
        default:
            return props.pending > 0 ? `${props.pending} queued` : 'Online';
    }
});

const tone = computed(() => {
    switch (state.value) {
        case 'locked':
            return 'border-destructive/50 bg-destructive/10 text-destructive';
        case 'offline':
            return 'border-primary/50 bg-primary/10 text-primary';
        default:
            return 'border-border text-muted-foreground';
    }
});

const icon = computed(() => {
    switch (state.value) {
        case 'locked':
            return LockKeyhole;
        case 'offline':
            return CloudOff;
        case 'syncing':
            return LoaderCircle;
        default:
            return Wifi;
    }
});
</script>

<template>
    <button
        type="button"
        class="press flex h-9 items-center gap-2 rounded-full border px-3 text-xs font-medium transition-colors"
        :class="tone"
        :title="authExpired ? 'Your session expired. Queued sales are safe.' : label"
        @click="emit('retry')"
    >
        <component :is="icon" class="size-3.5" :class="syncing ? 'animate-spin' : ''" />
        <span>{{ label }}</span>

        <!-- The count is the number the cashier actually cares about, so it
             gets its own chip rather than being buried in the label. -->
        <span
            v-if="pending > 0 && state !== 'offline'"
            class="tabular rounded-full bg-primary px-1.5 py-0.5 font-mono text-[0.65rem] text-primary-foreground"
        >
            {{ pending }}
        </span>

        <RefreshCw v-if="state === 'online' && pending > 0" class="size-3" />
    </button>
</template>
