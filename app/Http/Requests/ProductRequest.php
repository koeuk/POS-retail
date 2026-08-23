<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /** Authorisation is handled by ProductPolicy via authorizeResource(). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],

            /*
             * Pack sizes are one level deep on purpose. A pack of a pack would
             * need the base units multiplied down a chain, and no shop counts
             * stock that way — a case is 24 cans, full stop.
             *
             * Set directly only when something creates a pack row on its own;
             * the form builds packs through the `packs` array below instead.
             */
            'parent_product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->whereNull('parent_product_id'),
                Rule::notIn(array_filter([$productId])),
            ],
            'units_per_pack' => ['required_with:parent_product_id', 'integer', 'min:1', 'max:100000'],

            /*
             * Optional larger sizes of this same product, entered inline: a
             * beer bought 264 cans at a time and sold by the twelve, the six
             * and the single is one product with three extra prices, and
             * making the shopkeeper create three more products by hand is how
             * the stock figures end up disagreeing.
             */
            'packs' => ['array', 'max:20'],
            'packs.*.id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'packs.*.name' => ['required', 'string', 'max:255'],
            'packs.*.units_per_pack' => ['required', 'integer', 'min:2', 'max:100000'],
            'packs.*.sell_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'packs.*.barcode' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'barcode')->ignore($productId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            /*
             * One price. Cost is no longer captured, and tax is inherited from
             * the default_tax_rate setting rather than set per product — see
             * Product::effectiveTaxRate(). Neither key is validated here, so
             * neither reaches the model: an existing product keeps whatever
             * rate it already has, and a new one inherits.
             */
            'sell_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'unit' => ['required', 'string', 'max:20'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'max:2048'],

            // Opening stock, only meaningful on create.
            'opening_qty' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'That SKU is already used by another product.',
            'barcode.unique' => 'That barcode is already used by another product.',
            'parent_product_id.exists' => 'A pack must belong to a product that is not itself a pack.',
            'parent_product_id.not_in' => 'A product cannot be a pack of itself.',
            'units_per_pack.required_with' => 'Say how many units this pack contains.',
            'packs.*.name.required' => 'Give each pack size a name.',
            'packs.*.units_per_pack.required' => 'Say how many units the pack contains.',
            'packs.*.units_per_pack.min' => 'A pack has to hold at least two — one of something is the product itself.',
            'packs.*.sell_price.required' => 'Give each pack size a price.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'track_stock' => $this->boolean('track_stock'),
            'is_active' => $this->boolean('is_active'),
            'barcode' => $this->input('barcode') ?: null,
            'parent_product_id' => $this->input('parent_product_id') ?: null,
            // A base product always contains one of itself.
            'units_per_pack' => $this->input('parent_product_id') ? $this->input('units_per_pack') : 1,
        ]);
    }
}
