<?php

namespace App\Http\Controllers;

use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin's "Viewing" switcher: every screen at once, the admin's own shop,
 * or one vendor. Stored in the session and read by Tenant, so the choice
 * fences every query exactly as that vendor's own login is fenced.
 */
class ViewingController extends Controller
{
    public const ALL = 'all';

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'viewing' => ['required', 'string', Rule::when(
                ! in_array($request->input('viewing'), [self::ALL, Tenant::SHOP], true),
                [Rule::exists('vendors', 'id')],
            )],
        ]);

        $data['viewing'] === self::ALL
            ? $request->session()->forget(Tenant::SESSION_KEY)
            : $request->session()->put(Tenant::SESSION_KEY, $data['viewing']);

        return back();
    }
}
