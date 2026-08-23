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

const props = withDefaults(
    defineProps<{
        categories: Category[];
        product?: Product;
        /** The rate this product will actually be taxed at, from settings. */
        defaultTaxRate: number;
        /** Products a pack may belong to — base products only. */
        baseProducts?: Array<{ id: number; name: string; unit: string }>;
    }>(),
    { baseProducts: () => [] },
);

const isEdit = computed(() => !!props.product);

const NONE = 'none';

const form = useForm({
    category_id: props.product ? String(props.product.category_id) : '',
    parent_product_id: props.product?.parent_product_id ? String(props.product.parent_product_id) : NONE,
    units_per_pack: props.product?.units_per_pack ?? 1,
    name: props.product?.name ?? '',
    sku: props.product?.sku ?? '',
    barcode: props.product?.barcode ?? '',
    description: props.product?.description ?? '',
    sell_price: props.product?.sell_price ?? '0.00',
    unit: props.product?.unit ?? 'pcs',
    track_stock: props.product?.track_stock ?? true,
    is_active: props.product?.is_active ?? true,
    image: null as File | null,
    opening_qty: 0,
    low_stock_threshold: 10,
});

/*
 * A pack draws its stock from the product it belongs to, so it has no opening
 * quantity and no alert level of its own — those belong to the base product's
 * single shelf. The form hides them rather than collecting numbers the server
 * would throw away.
 */
const isPack = computed(() => form.parent_product_id !== NONE);

const parentUnit = computed(() => props.baseProducts.find((p) => String(p.id) === form.parent_product_id)?.unit ?? 'units');

const preview = ref<string | null>(props.product?.image ? `/storage/${props.product.image}` : null);

function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    preview.value = file ? URL.createObjectURL(file) : preview.value;
}

/** What the customer will actually be charged, once tax is added. */
const withTax = computed(() => {
    const sell = Number(form.sell_price) || 0;
    return sell * (1 + props.defaultTaxRate / 100);
});

function submit() {
    form.transform((data) => ({
        ...data,
        parent_product_id: data.parent_product_id === NONE ? null : data.parent_product_id,
    }));

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
    <form class="grid gap-5 lg:grid-cols-3" @submit.prevent="submit">
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
                <h2 class="mb-1 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Pack size</h2>
                <p class="mb-4 text-xs text-muted-foreground">
                    Selling the same thing by the case and by the single? Make the single unit the product, then add a pack for each larger size.
                    Stock is only ever counted in single units.
                </p>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="parent">This is</Label>
                        <Select v-model="form.parent_product_id">
                            <SelectTrigger id="parent">
                                <SelectValue placeholder="A product in its own right" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE">A product in its own right</SelectItem>
                                <SelectItem v-for="b in baseProducts" :key="b.id" :value="String(b.id)"> A pack of {{ b.name }} </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.parent_product_id" />
                    </div>

                    <div v-if="isPack" class="grid gap-2">
                        <Label for="units">How many {{ parentUnit }} in one?</Label>
                        <Input id="units" v-model="form.units_per_pack" type="number" min="1" class="tabular font-mono" />
                        <p class="text-xs text-muted-foreground">Selling one takes this many off the base product's stock.</p>
                        <InputError :message="form.errors.units_per_pack" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Pricing</h2>

                <div class="grid gap-2 sm:max-w-xs">
                    <Label for="sell">Sell price</Label>
                    <Input id="sell" v-model="form.sell_price" type="number" step="0.01" min="0" class="tabular font-mono" />
                    <InputError :message="form.errors.sell_price" />
                </div>

                <!--
                    Tax is not set per product any more: it comes from the
                    default rate in settings. Showing the tax-inclusive figure
                    here matters because the price typed above is the NET one,
                    and the number the customer sees is this one.
                -->
                <p class="mt-3 text-xs text-muted-foreground">
                    Tax-exclusive. At
                    <strong class="font-medium text-foreground">{{ defaultTaxRate }}%</strong> tax the customer pays
                    <strong class="tabular font-mono font-medium text-primary">{{ withTax.toFixed(2) }}</strong
                    >.
                </p>
            </section>

            <section v-if="!isEdit && !isPack" class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
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
            It rests just above the tab bar.

            Sticky, not fixed: AppLayout's entrance animation uses fill-mode
            `both`, so its wrapper keeps a transform after the animation ends —
            and a transformed ancestor becomes the containing block for any
            fixed child, which would anchor this bar to the foot of the page
            instead of the screen.
        -->
        <div
            class="sticky z-30 -mx-5 border-t border-border bg-background/95 px-5 py-3 backdrop-blur-md md:hidden"
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
