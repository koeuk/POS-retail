<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The per-row "History" button every list screen carries — one component so
 * they cannot drift. It opens the record's own history page (not the whole
 * Activity Log), and renders nothing for staff without the `activity`
 * permission: the route middleware is still the wall, this just hides a
 * locked door.
 */
const props = defineProps<{
    /** Short model class name, e.g. 'Product' — matches subject_type. */
    subjectType: string;
    subjectId: number;
    /** What the record is called, for the accessible label. */
    label: string;
}>();

const page = usePage<SharedData>();

const visible = computed(() => !!page.props.auth.can.activity);

const href = computed(() => route('activity.show', { subjectType: props.subjectType, subjectId: props.subjectId }));
</script>

<template>
    <Button v-if="visible" as-child variant="ghost" size="icon" class="press size-8">
        <Link :href="href" :aria-label="`History for ${label}`">
            <History class="size-4" />
        </Link>
    </Button>
</template>
