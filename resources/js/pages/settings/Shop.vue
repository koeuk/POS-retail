<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency, type CurrencyDef } from '@/composables/useCurrency';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    shop: {
        receipt_header: string;
        receipt_footer: string | null;
        currency: string;
        riel_per_usd: number;
    };
    currencies: { code: string; symbol: string; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shop settings', href: '/settings/shop' }];

const form = useForm({
    receipt_header: props.shop.receipt_header,
    receipt_footer: props.shop.receipt_footer ?? '',
    currency: props.shop.currency,
    riel_per_usd: String(props.shop.riel_per_usd),
});

/*
 * A live example of a $10.00 shelf price, so the effect of the rate is
 * visible before Save. Typing 4100 and seeing ៛41,000 is worth more than a
 * paragraph explaining it.
 */
const preview = computed(() => {
    const def: CurrencyDef = {
        code: form.currency,
        symbol: form.currency === 'KHR' ? '៛' : '$',
        decimals: form.currency === 'KHR' ? 0 : 2,
        riel_per_usd: Number(form.riel_per_usd) || 0,
    };
    return formatCurrency(10, def);
});

function submit() {
    form.put(route('shop.update'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Shop settings" />

        <SettingsLayout>
            <form class="space-y-10" @submit.prevent="submit">
                <!-- Currency -->
                <div class="space-y-6">
                    <HeadingSmall
                        title="Currency"
                        description="Prices are kept in US dollars. Choose what the till, receipts and menu show, and the rate used to convert."
                    />

                    <div class="grid gap-2">
                        <Label>Show prices in</Label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                v-for="c in currencies"
                                :key="c.code"
                                type="button"
                                class="press flex items-center gap-3 rounded-lg border p-3 text-left transition-colors"
                                :class="form.currency === c.code ? 'border-primary bg-primary/10' : 'border-border'"
                                @click="form.currency = c.code"
                            >
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted font-mono text-lg">
                                    {{ c.symbol }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{ c.name }}</span>
                                    <span class="block font-mono text-xs text-muted-foreground">{{ c.code }}</span>
                                </span>
                                <Check v-if="form.currency === c.code" class="size-4 shrink-0 text-primary" />
                            </button>
                        </div>
                        <InputError :message="form.errors.currency" />
                    </div>

                    <div class="grid gap-2 sm:max-w-xs">
                        <Label for="rate">Exchange rate</Label>
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 font-mono text-sm text-muted-foreground">$1 =</span>
                            <Input id="rate" v-model="form.riel_per_usd" type="number" min="1" step="1" inputmode="numeric" class="tabular font-mono" />
                            <span class="shrink-0 font-mono text-sm text-muted-foreground">៛</span>
                        </div>
                        <InputError :message="form.errors.riel_per_usd" />
                    </div>

                    <p class="rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm">
                        A <span class="tabular font-mono">$10.00</span> item will show as
                        <strong class="tabular font-mono text-primary">{{ preview }}</strong>
                    </p>
                </div>

                <!-- Receipt -->
                <div class="space-y-6">
                    <HeadingSmall title="Receipt" description="Printed at the top and bottom of every receipt." />

                    <div class="grid gap-2">
                        <Label for="header">Header</Label>
                        <Input id="header" v-model="form.receipt_header" required />
                        <InputError :message="form.errors.receipt_header" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="footer">Footer</Label>
                        <Textarea id="footer" v-model="form.receipt_footer" rows="2" placeholder="Thank you for shopping with us!" />
                        <InputError :message="form.errors.receipt_footer" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button type="submit" class="press" :disabled="form.processing">Save</Button>
                    <Transition
                        enter-from-class="opacity-0"
                        enter-active-class="transition-opacity duration-200"
                        leave-to-class="opacity-0"
                        leave-active-class="transition-opacity duration-500"
                    >
                        <p v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                    </Transition>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
