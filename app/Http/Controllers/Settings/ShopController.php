<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop-wide settings: what the receipt says and which currency prices show in.
 *
 * Admin only. Everything else under /settings is about the signed-in person;
 * this screen changes what every cashier and every customer sees, which is a
 * decision for whoever owns the shop.
 */
class ShopController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Shop', [
            'shop' => [
                'receipt_header' => Setting::get('receipt_header', config('app.name')),
                'receipt_footer' => Setting::get('receipt_footer'),
                'currency' => Currency::current()->code,
                'riel_per_usd' => Currency::current()->rielPerUsd,
            ],
            'currencies' => collect(Currency::DEFINITIONS)
                ->map(fn (array $def, string $code) => [
                    'code' => $code,
                    'symbol' => $def['symbol'],
                    'name' => $def['name'],
                ])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        try {
            $data = $request->validate([
                'receipt_header' => ['required', 'string', 'max:120'],
                'receipt_footer' => ['nullable', 'string', 'max:255'],
                'currency' => ['required', Rule::in(array_keys(Currency::DEFINITIONS))],
                // A rate of 0 would turn every riel price into ៛0, and a rate in
                // the millions is a typo, not an economy. Bound it sensibly.
                'riel_per_usd' => ['required', 'numeric', 'min:1', 'max:100000'],
            ]);

            foreach ($data as $key => $value) {
                Setting::put($key, $value === null ? null : (string) $value);
            }

            return back()->with('success', 'Shop settings saved.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The settings could not be saved. Nothing was changed — try again.');
        }
    }
}
