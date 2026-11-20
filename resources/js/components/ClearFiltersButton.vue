<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { X } from 'lucide-vue-next';

defineProps<{ disabled?: boolean }>();
defineEmits<{ clear: [] }>();
</script>

<template>
    <Button
        type="button"
        variant="outline"
        class="press h-10 shrink-0 gap-1.5 rounded-full"
        :disabled="disabled"
        aria-label="Clear search and filters"
        @click="$emit('clear')"
    >
        <X class="size-4" />
        Clear
    </Button>
</template>
