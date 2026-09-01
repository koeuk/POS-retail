<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = QueryBuilder::for(Customer::class)
            ->withCount('orders')
            ->withSum('orders as spent_total', 'total')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
                }),
            ])
            ->orderBy('name')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return response()->json($customers);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        try {
            $customer = DB::transaction(fn () => Customer::create($request->validated()));

            return response()->json($customer, 201);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['message' => 'The customer could not be saved — try again.'], 503);
        }
    }
}
