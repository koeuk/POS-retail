<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { Category, Product } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';
import { ImageUp, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    categories: Category[];
    product?: Product;
}>();

const isEdit = computed(() => !!props.product);

const form = useForm({
    category_id: props.product ? String(props.product.category_id) : '',
    name: props.product?.name ?? '',
    sku: props.product?.sku ?? '',
    barcode: props.product?.barcode ?? '',
    description: props.product?.description ?? '',
    cost_price: props.product?.cost_price ?? '0.00',
    sell_price: props.product?.sell_price ?? '0.00',
    tax_rate: props.product?.tax_rate ?? '',
    unit: props.product?.unit ?? 'pcs',
    track_stock: props.product?.track_stock ?? true,
    is_active: props.product?.is_active ?? true,
    image: null as File | null,
    opening_qty: 0,
    low_stock_threshold: 10,
});

const preview = ref<string | null>(props.product?.image ? `/storage/${props.product.image}` : null);

function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    preview.value = file ? URL.createObjectURL(file) : preview.value;
}

/** Retail margin is the number a buyer actually cares about while typing. */
const margin = computed(() => {
    const cost = Number(form.cost_price) || 0;
    const sell = Number(form.sell_price) || 0;
    if (sell <= 0) return null;
    return ((sell - cost) / sell) * 100;
});

function submit() {
    if (isEdit.value) {
        // Multipart cannot be sent as a real PUT, so spoof the method.
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('products.update', { product: props.product!.id }), {
            forceFormData: true,
        });
    } else {
        form.post(route('products.store'), { forceFormData: true });
    }
}
</script>

<template>
    <form class="grid gap-5 pb-20 md:pb-0 lg:grid-cols-3" @submit.prevent="submit">
        <!-- Main -->
        <div class="stagger space-y-5 lg:col-span-2">
            <section class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Details</h2>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required autofocus />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="category">Category</Label>
                        <Select v-model="form.category_id">
                            <SelectTrigger id="category">
                                <SelectValue placeholder="Choose a category" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="c in categories" :key="c.id" :value="String(c.id)">
                                    {{ c.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.category_id" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="sku">SKU</Label>
                            <Input id="sku" v-model="form.sku" class="font-mono" required />
                            <InputError :message="form.errors.sku" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="barcode">Barcode</Label>
                            <Input id="barcode" v-model="form.barcode" class="font-mono" placeholder="Optional" />
                            <InputError :message="form.errors.barcode" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <Textarea id="description" v-model="form.description" rows="3" placeholder="Optional" />
                        <InputError :message="form.errors.description" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Pricing</h2>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="cost">Cost price</Label>
                        <Input id="cost" v-model="form.cost_price" type="number" step="0.01" min="0" class="tabular font-mono" />
                        <InputError :message="form.errors.cost_price" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sell">Sell price</Label>
                        <Input id="sell" v-model="form.sell_price" type="number" step="0.01" min="0" class="tabular font-mono" />
                        <InputError :message="form.errors.sell_price" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="tax">Tax rate %</Label>
                        <Input
                            id="tax"
                            v-model="form.tax_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            placeholder="0"
                            class="tabular font-mono"
                        />
                        <InputError :message="form.errors.tax_rate" />
                    </div>
                </div>

                <p class="mt-3 text-xs text-muted-foreground">
                    Prices are <strong class="font-medium text-foreground">tax-exclusive</strong> — tax is added per line at checkout. Leave the rate
                    blank for 0%.
                    <span v-if="margin !== null" class="ml-1">
                        Margin
                        <span class="tabular font-mono font-medium" :class="margin < 0 ? 'text-destructive' : 'text-primary'">
                            {{ margin.toFixed(1) }}%
                        </span>
                    </span>
                </p>
            </section>

            <section v-if="!isEdit" class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Opening stock</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="qty">Quantity per store</Label>
                        <Input id="qty" v-model="form.opening_qty" type="number" min="0" class="tabular font-mono" />
                        <InputError :message="form.errors.opening_qty" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="threshold">Low-stock alert at</Label>
                        <Input id="threshold" v-model="form.low_stock_threshold" type="number" min="0" class="tabular font-mono" />
                        <InputError :message="form.errors.low_stock_threshold" />
                    </div>
                </div>
            </section>
        </div>

        <!-- Aside -->
        <div class="stagger space-y-5">
            <section class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Image</h2>

                <label
                    class="lift flex h-36 w-full cursor-pointer items-center justify-center overflow-hidden rounded-lg border border-dashed border-border bg-muted/40 lg:aspect-square lg:h-auto"
                >
                    <img v-if="preview" :src="preview" alt="" class="size-full object-cover" />
                    <div v-else class="flex flex-col items-center gap-2 text-muted-foreground">
                        <ImageUp class="size-6" />
                        <span class="text-xs">Click to upload</span>
                    </div>
                    <input type="file" accept="image/*" class="sr-only" @change="onFile" />
                </label>
                <InputError class="mt-2" :message="form.errors.image" />
            </section>

            <section class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Options</h2>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="unit">Unit</Label>
                        <Input id="unit" v-model="form.unit" placeholder="pcs" />
                        <InputError :message="form.errors.unit" />
                    </div>

                    <div class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                        <div>
                            <p class="text-sm font-medium">Track stock</p>
                            <p class="text-xs text-muted-foreground">Decrement on every sale</p>
                        </div>
                        <Switch v-model="form.track_stock" />
                    </div>

                    <div class="flex items-center justify-between gap-3 rounded-lg border border-border p-3">
                        <div>
                            <p class="text-sm font-medium">Active</p>
                            <p class="text-xs text-muted-foreground">Show on the POS grid</p>
                        </div>
                        <Switch v-model="form.is_active" />
                    </div>
                </div>
            </section>

            <!-- Desktop: the action pair sits at the foot of the aside column. -->
            <div class="hidden items-center gap-2 md:flex">
                <Button type="submit" class="press flex-1" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    {{ isEdit ? 'Save changes' : 'Create product' }}
                </Button>
                <Button as-child type="button" variant="ghost" class="press">
                    <Link :href="route('products.index')">Cancel</Link>
                </Button>
            </div>
        </div>

        <!--
            Phone: a docked action bar. This form is taller than the screen, so
            a submit button at the end of the document means scrolling past the
            image picker and both switches to commit an edit made at the top.
            It sits directly above the tab bar and carries the home indicator's
            inset itself.
        -->
        <div
            class="fixed inset-x-0 z-30 border-t border-border bg-background/95 px-5 py-3 backdrop-blur-md md:hidden"
            style="bottom: calc(var(--tabbar-h) + var(--safe-bottom))"
        >
            <div class="flex items-center gap-2">
                <Button as-child type="button" variant="outline" class="press">
                    <Link :href="route('products.index')">Cancel</Link>
                </Button>
                <Button type="submit" class="press flex-1" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    {{ isEdit ? 'Save changes' : 'Create product' }}
                </Button>
            </div>
        </div>
    </form>
</template>
