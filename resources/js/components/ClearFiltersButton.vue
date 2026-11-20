<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { X } from 'lucide-vue-next';

defineProps<{ disabled?: boolean; compact?: boolean }>();
defineEmits<{ clear: [] }>();
</script>

<template>
    <Button
        type="button"
        variant="outline"
        class="press shrink-0 gap-1.5 rounded-full"
        :class="compact ? 'size-8 border-transparent p-0' : 'h-10'"
        :disabled="disabled"
        :aria-label="compact ? 'Clear search' : 'Clear search and filters'"
        @click="$emit('clear')"
    >
        <X class="size-4" />
        <span v-if="!compact">Clear</span>
    </Button>
</template>
