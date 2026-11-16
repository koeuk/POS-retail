<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Camera, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { imageSrc } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

const page = usePage<SharedData>();
const user = page.props.auth.user as User;
const { getInitials } = useInitials();

const blobUrl = ref<string | null>(null);
const avatarInput = ref<HTMLInputElement>();

const form = useForm({
    name: user.name,
    email: user.email,
    avatar: null as File | null,
    remove_avatar: false,
});

const savedAvatar = computed(() => imageSrc(user.avatar));

const avatarPreview = computed(() => {
    if (blobUrl.value) return blobUrl.value;
    if (form.remove_avatar) return null;
    return savedAvatar.value ?? null;
});

function pickAvatar(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    dropBlob();
    blobUrl.value = URL.createObjectURL(file);
    form.avatar = file;
    form.remove_avatar = false;
    input.value = '';
}

function removeAvatar() {
    dropBlob();
    form.avatar = null;
    form.remove_avatar = true;
    if (avatarInput.value) avatarInput.value.value = '';
}

function dropBlob() {
    if (blobUrl.value) {
        URL.revokeObjectURL(blobUrl.value);
        blobUrl.value = null;
    }
}

onBeforeUnmount(() => {
    dropBlob();
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        _method: 'patch',
    })).post(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            dropBlob();
            form.reset('avatar', 'remove_avatar');
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Profile settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall title="Profile information" description="Update your name, email address, and profile photo" />

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="grid gap-2">
                        <Label>Profile photo</Label>
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                            <div class="relative size-20 shrink-0">
                                <div class="flex size-20 items-center justify-center overflow-hidden rounded-full border border-border bg-muted/50 shadow-xs">
                                    <img
                                        v-if="avatarPreview"
                                        :src="avatarPreview"
                                        :alt="form.name || user.name"
                                        class="size-full object-cover"
                                    />
                                    <span v-else class="select-none text-xl font-medium text-muted-foreground">
                                        {{ getInitials(form.name || user.name) }}
                                    </span>
                                </div>
                                <button
                                    v-if="avatarPreview"
                                    type="button"
                                    class="press absolute -right-1 -top-1 grid size-6 place-items-center rounded-full bg-background/90 text-muted-foreground shadow-sm ring-1 ring-border hover:text-destructive"
                                    aria-label="Remove photo"
                                    @click="removeAvatar"
                                >
                                    <X class="size-3.5" />
                                </button>
                            </div>

                            <div class="flex-1 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="cursor-pointer"
                                        @click="avatarInput?.click()"
                                    >
                                        <Camera class="mr-1.5 size-4 text-muted-foreground" />
                                        <span>{{ avatarPreview ? 'Change photo' : 'Upload photo' }}</span>
                                    </Button>
                                    <Button
                                        v-if="avatarPreview"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        @click="removeAvatar"
                                    >
                                        Remove
                                    </Button>
                                </div>
                                <p class="text-xs text-muted-foreground">JPG, PNG, GIF, or WebP. Max 2 MB.</p>
                                <InputError :message="form.errors.avatar" />
                            </div>

                            <input
                                ref="avatarInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp,image/gif"
                                class="hidden"
                                @change="pickAvatar"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" class="mt-1 block w-full" v-model="form.name" required autocomplete="name" placeholder="Full name" />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at">
                        <p class="mt-2 text-sm text-neutral-800">
                            Your email address is unverified.
                            <Link
                                :href="route('verification.send')"
                                method="post"
                                as="button"
                                class="focus:outline-hidden rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        <div v-if="status === 'verification-link-sent'" class="mt-2 text-sm font-medium text-green-600">
                            A new verification link has been sent to your email address.
                        </div>
                    </div>

                    <div class="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center">
                        <Button class="w-full sm:w-auto" :disabled="form.processing">Save</Button>

                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out"
                            enter-from="opacity-0"
                            leave="transition ease-in-out"
                            leave-to="opacity-0"
                        >
                            <p class="text-sm text-neutral-600">Saved.</p>
                        </TransitionRoot>
                    </div>
                </form>
            </div>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
