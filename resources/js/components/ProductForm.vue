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
import { ImageUp, LoaderCircle, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface PackRow {
    /* Inertia's useForm only accepts FormDataConvertible values, and an array
       of plain objects needs this index signature to qualify. */
    [key: string]: string | number | null;
    id: number | null;
    name: string;
    units_per_pack: number | string;
    sell_price: string;
}

const props = withDefaults(
    defineProps<{
        categories: Category[];
        product?: Product;
        /** Larger sizes already saved against this product. */
        packs?: Array<{ id: number; name: string; units_per_pack: number; sell_price: string }>;
    }>(),
    { packs: () => [] },
);

const isEdit = computed(() => !!props.product);

const form = useForm({
    category_id: props.product ? String(props.product.category_id) : '',
    packs: props.packs.map((p): PackRow => ({ id: p.id, name: p.name, units_per_pack: p.units_per_pack, sell_price: p.sell_price })),
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
 * Larger sizes of this same product. Stock is always counted in the base unit,
 * so a pack needs only a name, how many it holds, and a price — no stock of
 * its own, and no SKU to invent: the server derives one.
 */
function addPack() {
    form.packs.push({ id: null, name: '', units_per_pack: 6, sell_price: form.sell_price });
}

function removePack(index: number) {
    form.packs.splice(index, 1);
}

/** What one unit inside the pack works out at — the number that decides whether the bulk price makes sense. */
function perUnit(pack: PackRow): number | null {
    const units = Number(pack.units_per_pack) || 0;
    const price = Number(pack.sell_price) || 0;
    if (units <= 0 || price <= 0) return null;

    return price / units;
}

const packError = (index: number, field: string) => (form.errors as Record<string, string | undefined>)[`packs.${index}.${field}`];

const preview = ref<string | null>(props.product?.image ? `/storage/${props.product.image}` : null);

function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    preview.value = file ? URL.createObjectURL(file) : preview.value;
}

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
                <div class="mb-1 flex items-center justify-between gap-3">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Also sold in packs</h2>
                    <span class="text-xs text-muted-foreground">Optional</span>
                </div>
                <p class="mb-4 text-xs text-muted-foreground">
                    Selling the same thing by the twelve, the six and the single? Add a row for each larger size. Stock stays counted in
                    {{ form.unit || 'single units' }} — selling one pack takes its whole contents off the same shelf.
                </p>

                <div v-if="form.packs.length" class="mb-3 space-y-3">
                    <div v-for="(pack, index) in form.packs" :key="index" class="rounded-lg border border-border p-3">
                        <div class="grid gap-3 sm:grid-cols-[1fr_6rem_7rem_auto] sm:items-start">
                            <div class="grid gap-1.5">
                                <Label :for="`pack-name-${index}`" class="text-xs text-muted-foreground">Name</Label>
                                <Input :id="`pack-name-${index}`" v-model="pack.name" placeholder="Half case" />
                                <InputError :message="packError(index, 'name')" />
                            </div>

                            <div class="grid gap-1.5">
                                <Label :for="`pack-units-${index}`" class="text-xs text-muted-foreground">Holds</Label>
                                <Input :id="`pack-units-${index}`" v-model="pack.units_per_pack" type="number" min="2" class="tabular font-mono" />
                                <InputError :message="packError(index, 'units_per_pack')" />
                            </div>

                            <div class="grid gap-1.5">
                                <Label :for="`pack-price-${index}`" class="text-xs text-muted-foreground">Price</Label>
                                <Input
                                    :id="`pack-price-${index}`"
                                    v-model="pack.sell_price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="tabular font-mono"
                                />
                                <InputError :message="packError(index, 'sell_price')" />
                            </div>

                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="press touch-target mt-0 self-start text-muted-foreground hover:text-destructive sm:mt-[1.6rem]"
                                :aria-label="`Remove ${pack.name || 'pack'}`"
                                @click="removePack(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>

                        <!-- The per-unit figure is what tells you whether the bulk
                             price is actually a discount. -->
                        <p v-if="perUnit(pack) !== null" class="mt-2 text-xs text-muted-foreground">
                            <span class="tabular font-mono">{{ perUnit(pack)!.toFixed(3) }}</span>
                            each
                        </p>
                    </div>
                </div>

                <Button type="button" variant="outline" class="press w-full sm:w-auto" @click="addPack">
                    <Plus class="size-4" />
                    Add a pack size
                </Button>
            </section>

            <section class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <h2 class="mb-4 font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Pricing</h2>

                <div class="grid gap-2 sm:max-w-xs">
                    <Label for="sell">Sell price</Label>
                    <Input id="sell" v-model="form.sell_price" type="number" step="0.01" min="0" class="tabular font-mono" />
                    <InputError :message="form.errors.sell_price" />
                </div>

                <p class="mt-3 text-xs text-muted-foreground">This is the price the customer pays.</p>
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
