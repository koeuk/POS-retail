<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History } from 'lucide-vue-next';

interface Change {
    field: string;
    from: string | null;
    to: string | null;
}

interface ActivityRow {
    id: number;
    log_name: string | null;
    description: string;
    event: string | null;
    causer: { id: number; name: string; email: string } | null;
    store: { id: number; name: string } | null;
    ip_address: string | null;
    changes: Change[];
    properties: Record<string, string>;
    created_at: string | null;
}

const props = defineProps<{
    entries: Paginated<ActivityRow>;
    subject: { type: string; id: number; label: string; exists: boolean };
}>();

/** Colour by what happened to the record, so the timeline skims at a glance. */
const toneFor: Record<string, string> = {
    created: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    updated: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    deleted: 'bg-destructive/10 text-destructive',
};

const dateFormat = new Intl.DateTimeFormat(undefined, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const when = (iso: string | null) => (iso ? dateFormat.format(new Date(iso)) : '—');

const fieldLabel = (field: string) =>
    field.replace(/_id$/, '').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

const title = `History — ${props.subject.label}`;
</script>

<template>
    <Head :title="title" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Activity Log', href: '/activity' },
            { title: subject.label, href: `/activity/${subject.type}/${subject.id}` },
        ]"
    >
        <div class="px-2.5 py-6 md:px-8">
            <PageHeader
                :eyebrow="`Audit · ${subject.type} #${subject.id}`"
                :title="subject.label"
                :description="
                    subject.exists
                        ? 'Every recorded change to this record, newest first.'
                        : 'This record has since been deleted — its history remains.'
                "
            >
                <template #actions>
                    <Button as-child variant="outline" class="press">
                        <Link :href="route('activity.index')">
                            <ArrowLeft class="size-4" />
                            Whole log
                        </Link>
                    </Button>
                </template>
            </PageHeader>

            <div class="list-panel animate-rise" style="animation-delay: 60ms">
                <!-- One timeline, newest first. The rail keeps the eye moving
                     down one record's life rather than scanning a table. -->
                <ol v-if="entries.data.length" class="p-4 md:p-6">
                    <li v-for="(a, i) in entries.data" :key="a.id" class="relative flex gap-4 pb-6 last:pb-0">
                        <div class="flex flex-col items-center">
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-full"
                                :class="toneFor[a.event ?? ''] ?? 'bg-muted text-muted-foreground'"
                            >
                                <History class="size-4" />
                            </span>
                            <span v-if="i < entries.data.length - 1" class="mt-1 w-px flex-1 bg-border" />
                        </div>

                        <div class="min-w-0 flex-1 pt-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <p class="font-medium leading-tight">{{ a.description }}</p>
                                <Badge
                                    v-if="a.event"
                                    variant="secondary"
                                    class="text-[10px]"
                                    :class="toneFor[a.event] ?? ''"
                                >
                                    {{ fieldLabel(a.event) }}
                                </Badge>
                            </div>

                            <p class="mt-0.5 text-xs text-muted-foreground">
                                <span class="tabular font-mono">{{ when(a.created_at) }}</span>
                                ·
                                <span>{{ a.causer?.name ?? 'System' }}</span>
                                <template v-if="a.store"> · {{ a.store.name }}</template>
                                <template v-if="a.ip_address">
                                    · <span class="tabular font-mono">{{ a.ip_address }}</span>
                                </template>
                            </p>

                            <ul v-if="a.changes.length" class="mt-2 space-y-0.5">
                                <li v-for="c in a.changes" :key="c.field" class="text-xs leading-snug">
                                    <span class="text-muted-foreground">{{ fieldLabel(c.field) }}:</span>
                                    <span v-if="c.from" class="tabular font-mono text-muted-foreground line-through">{{ c.from }}</span>
                                    <span v-else class="text-muted-foreground italic">empty</span>
                                    <span class="text-muted-foreground"> → </span>
                                    <span v-if="c.to" class="tabular font-mono">{{ c.to }}</span>
                                    <span v-else class="text-muted-foreground italic">empty</span>
                                </li>
                            </ul>

                            <p v-if="Object.keys(a.properties).length" class="mt-2 text-xs text-muted-foreground">
                                <span v-for="(value, key) in a.properties" :key="key" class="mr-2 inline-block">
                                    {{ fieldLabel(String(key)) }}: <span class="tabular font-mono">{{ value }}</span>
                                </span>
                            </p>
                        </div>
                    </li>
                </ol>

                <EmptyState
                    v-else
                    :icon="History"
                    :title="`Nothing recorded for ${subject.label} yet`"
                    description="This record has not been changed since the activity log was switched on."
                />

                <Pagination
                    :links="entries.links"
                    :from="entries.from"
                    :to="entries.to"
                    :total="entries.total"
                    :per-page="entries.per_page"
                />
            </div>
        </div>
    </AppLayout>
</template>
