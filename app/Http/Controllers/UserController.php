<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\UserRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::query()
                ->with('store:id,name')
                ->when($request->input('search'), function ($query, string $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->when($request->input('role'), fn ($q, $role) => $q->where('role', $role))
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString(),
            'stores' => Store::orderBy('name')->get(['id', 'name']),
            'roles' => collect(Role::cases())->map(fn (Role $r) => [
                'value' => $r->value,
                'label' => $r->label(),
            ]),
            'filters' => $request->only('search', 'role'),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        $data['email_verified_at'] = now(); // staff accounts are created by an admin

        User::create($data);

        return back()->with('success', 'Staff account created.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
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

        $user->update($data);

        return back()->with('success', 'Staff account updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        if ($user->orders()->exists()) {
            $user->update(['is_active' => false]);

            return back()->with('success', "{$user->name} has sales history, so the account was deactivated instead of deleted.");
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "{$name} was deleted.");
    }
}
