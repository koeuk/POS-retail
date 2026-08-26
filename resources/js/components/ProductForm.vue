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
        /** Stores that can receive goods, and what is on the shelf now. */
        stores?: Array<{ id: number; name: string }>;
        onHand?: number;
    }>(),
    { packs: () => [], stores: () => [], onHand: 0 },
);

const isEdit = computed(() => !!props.product);

/** Sentinel for "received as single units", since a Select needs a string. */
const SINGLE = 'single';

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

    /*
     * Goods received. Left blank normally — it is an action, not a setting, so
     * it clears itself after saving rather than sitting there ready to double
     * the stock on the next unrelated edit.
     */
    add_stock: '' as string | number,
    add_stock_pack_id: SINGLE as string,
    add_stock_loose: '' as string | number,
    add_stock_store_id: '' as string,
    add_stock_note: '',
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

/**
 * Saved packs only. A row still being typed has no id yet, so it cannot be
 * received against — it has to be saved before it can be counted in.
 */
const receivableUnits = computed(() => props.packs.filter((p) => !!p.id));

/** Base units in one of whatever is selected. */
const unitsPerSelected = computed(() => {
    if (form.add_stock_pack_id === SINGLE) return 1;

    return receivableUnits.value.find((p) => String(p.id) === form.add_stock_pack_id)?.units_per_pack ?? 1;
});

/** Base units this delivery adds: the packs, plus anything loose. */
const receivingTotal = computed(() => (Number(form.add_stock) || 0) * unitsPerSelected.value + (Number(form.add_stock_loose) || 0));

/** What the shelf will read once this save goes through. */
const stockAfter = computed(() => props.onHand + receivingTotal.value);

/**
 * The shelf figure said the way a shopkeeper counts it: "4 cases + 4" rather
 * than "100". Uses the largest pack, since that is what is stacked; anything
 * that does not fill one is the remainder.
 */
const onHandBreakdown = computed(() => {
    const largest = receivableUnits.value.reduce<null | { name: string; units_per_pack: number }>(
        (top, pack) => (!top || pack.units_per_pack > top.units_per_pack ? pack : top),
        null,
    );

    if (!largest || largest.units_per_pack < 2 || props.onHand < largest.units_per_pack) return null;

    const whole = Math.floor(props.onHand / largest.units_per_pack);
    const rest = props.onHand % largest.units_per_pack;

    return `${whole} × ${largest.name}${rest ? ` + ${rest} ${props.product?.unit ?? ''}`.trimEnd() : ''}`;
});

function submit() {
    if (isEdit.value) {
        // Multipart cannot be sent as a real PUT, so spoof the method.
        form.transform((data) => ({ ...data, _method: 'put' })).post(route('products.update', { product: props.product!.id }), {
            forceFormData: true,
            /*
             * Receiving is an action, not a setting. The update redirects away
             * so this rarely matters — but if it ever stops redirecting, a
             * second save would book the same delivery twice.
             */
            onSuccess: () => form.reset('add_stock', 'add_stock_note'),
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

            <!--
                Receiving goods, without a trip to Inventory.

                It is written as a Restock movement, not as a new figure typed
                over the old one: the quantity on the shelf has to stay
                explainable, and "someone edited it" is not an explanation. The
                field is blank by default because it is an action — leaving it
                filled would add the same delivery again on the next save.
            -->
            <section v-if="isEdit" class="rounded-xl border border-border bg-card p-4 shadow-sm md:p-5">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <h2 class="font-display text-sm font-semibold uppercase tracking-wide text-muted-foreground">Add stock</h2>
                    <span class="tabular text-right font-mono text-xs text-muted-foreground">
                        {{ onHand }} {{ form.unit }} on hand
                        <span v-if="onHandBreakdown" class="block text-[0.65rem] text-muted-foreground/70">{{ onHandBreakdown }}</span>
                    </span>
                </div>
                <p class="mb-4 text-xs text-muted-foreground">
                    Goods arrived from a supplier. Recorded as a restock movement, so Inventory can still say where the figure came from.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="add-stock">Quantity received</Label>
                        <div class="flex gap-2">
                            <Input id="add-stock" v-model="form.add_stock" type="number" min="0" placeholder="0" class="tabular w-24 font-mono" />
                            <!-- Counted the way the delivery arrived: three cases,
                                 not seventy-two packets. -->
                            <Select v-if="receivableUnits.length" v-model="form.add_stock_pack_id">
                                <SelectTrigger class="flex-1"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="SINGLE">{{ form.unit || 'single' }}</SelectItem>
                                    <SelectItem v-for="pack in receivableUnits" :key="pack.id" :value="String(pack.id)">
                                        {{ pack.name }} (×{{ pack.units_per_pack }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <span v-else class="flex items-center text-sm text-muted-foreground">{{ form.unit }}</span>
                        </div>
                        <InputError :message="form.errors.add_stock" />
                    </div>

                    <!-- The remainder that did not come in a full pack. -->
                    <div v-if="receivableUnits.length && form.add_stock_pack_id !== SINGLE" class="grid gap-2">
                        <Label for="add-stock-loose">Plus loose {{ form.unit }}</Label>
                        <Input id="add-stock-loose" v-model="form.add_stock_loose" type="number" min="0" placeholder="0" class="tabular font-mono" />
                        <InputError :message="form.errors.add_stock_loose" />
                    </div>

                    <p v-if="receivingTotal > 0" class="text-xs text-muted-foreground sm:col-span-2">
                        Adds <span class="tabular font-mono text-foreground">{{ receivingTotal }}</span> {{ form.unit }} — will be
                        <span class="tabular font-mono text-foreground">{{ stockAfter }}</span> {{ form.unit }} on hand.
                    </p>

                    <!-- Only worth asking when there is more than one answer. -->
                    <div v-if="stores.length > 1" class="grid gap-2">
                        <Label for="add-stock-store">Into which store</Label>
                        <Select v-model="form.add_stock_store_id">
                            <SelectTrigger id="add-stock-store">
                                <SelectValue :placeholder="stores[0]?.name" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="s in stores" :key="s.id" :value="String(s.id)">{{ s.name }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.add_stock_store_id" />
                    </div>

                    <div class="grid gap-2" :class="stores.length > 1 ? 'sm:col-span-2' : ''">
                        <Label for="add-stock-note">Note</Label>
                        <Input id="add-stock-note" v-model="form.add_stock_note" placeholder="Optional — supplier, invoice number" />
                        <InputError :message="form.errors.add_stock_note" />
                    </div>
                </div>
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
