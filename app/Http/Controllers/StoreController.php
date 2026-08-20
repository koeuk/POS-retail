<?php

namespace App\Http\Controllers;

use App\Models\Register;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Store::class);

        return Inertia::render('Stores/Index', [
            'stores' => Store::query()
                ->with('registers:id,store_id,name,is_active')
                ->withCount(['users', 'orders'])
                ->orderBy('name')
                ->get(),
            'canManage' => $request->user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Store::class);

        Store::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]));

        return back()->with('success', 'Store created.');
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('update', $store);

        $store->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]));

        return back()->with('success', 'Store updated.');
    }

    public function storeRegister(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('update', $store);

        $store->registers()->create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Register added.');
    }

    public function updateRegister(Request $request, Store $store, Register $register): RedirectResponse
    {
        $this->authorize('update', $store);

        abort_unless($register->store_id === $store->id, 404);

        $register->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'store_id' => ['sometimes', Rule::in([$store->id])],
        ]));

        return back()->with('success', 'Register updated.');
    }
}
