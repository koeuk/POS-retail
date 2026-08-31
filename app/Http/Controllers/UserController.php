<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\UserRequest;
use App\Models\Store;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('Users/Index', [
            'users' => QueryBuilder::for(User::class)
                ->with('store:id,name')
                ->allowedFilters(...[
                    AllowedFilter::callback('search', function (Builder $query, string $search) {
                        $query->where(function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),
                    AllowedFilter::exact('role'),
                ])
                ->orderBy('name')
                ->paginate(PerPage::resolve($request))
                ->withQueryString(),
            'stores' => Store::orderBy('name')->get(['id', 'name']),
            'roles' => collect(Role::cases())->map(fn (Role $r) => [
                'value' => $r->value,
                'label' => $r->label(),
            ]),
            'filters' => ['search' => (string) $request->input('filter.search', ''), 'role' => (string) $request->input('filter.role', '')],
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', User::class);

            $data = $request->validated();
            $data['password'] = Hash::make($data['password']);
            $data['email_verified_at'] = now(); // staff accounts are created by an admin

            DB::transaction(fn () => User::create($data));

            return back()->with('success', 'Staff account created.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The account could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        try {
            $this->authorize('update', $user);

            $data = $request->validated();

            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // An admin must not be able to lock themselves out of their own
            // account mid-session by demoting or deactivating it.
            if ($user->id === $request->user()->id) {
                $data['role'] = $user->role->value;
                $data['is_active'] = true;
            }

            DB::transaction(fn () => $user->update($data));

            return back()->with('success', 'Staff account updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The account could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->authorize('delete', $user);

            if ($user->id === $request->user()->id) {
                return back()->withErrors(['user' => 'You cannot delete your own account.']);
            }

            if ($user->orders()->exists()) {
                DB::transaction(fn () => $user->update(['is_active' => false]));

                return back()->with('success', "{$user->name} has sales history, so the account was deactivated instead of deleted.");
            }

            $name = $user->name;
            DB::transaction(fn () => $user->delete());

            return back()->with('success', "{$name} was deleted.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The account could not be removed. Nothing was changed — try again.');
        }
    }
}
