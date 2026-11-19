<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitRequest;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Unit::class);

        return Inertia::render('Units/Index', [
            'units' => Unit::withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $this->authorize('create', Unit::class);
        try {
            DB::transaction(fn () => Unit::create($request->validated()));

            return back()->with('success', 'Unit created.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The unit could not be saved. Try again.');
        }
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->authorize('update', $unit);
        if ($request->symbol !== $unit->symbol && $this->inUse($unit)) {
            return back()->withErrors(['symbol' => 'This unit is used by products. Its symbol cannot be changed.']);
        }
        try {
            DB::transaction(fn () => $unit->update($request->validated()));

            return back()->with('success', 'Unit updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The unit could not be saved. Try again.');
        }
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $this->authorize('delete', $unit);
        if ($this->inUse($unit)) {
            return back()->withErrors(['unit' => 'This unit is used by products and cannot be deleted.']);
        }
        try {
            DB::transaction(fn () => $unit->delete());

            return back()->with('success', 'Unit deleted.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The unit could not be deleted. Try again.');
        }
    }

    private function inUse(Unit $unit): bool
    {
        return Product::where('unit_id', $unit->id)->orWhere('unit', $unit->symbol)->exists();
    }
}
