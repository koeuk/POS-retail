# Roles & Permissions

How access control works in POS Retail, the patterns every new feature must follow, and the mistakes that reintroduce the bugs this design exists to prevent.

## The model in one paragraph

Every user has exactly one **role** — `admin`, `manager`, or `cashier` ([app/Enums/Role.php](../app/Enums/Role.php)). A role is only a *baseline*: it decides what a user can do **by default**. The real unit of access is the **permission** — one key per feature area ([app/Enums/Permission.php](../app/Enums/Permission.php)) — and any individual user can be granted or denied any permission on the Staff screen, regardless of role. Admins bypass the whole table: an admin always holds every permission, so the shop can never lock itself out of its own back office.

## Roles

| Role | Store binding | Default access |
|---|---|---|
| `admin` | none (sees all stores) | Everything, always. Overrides are ignored. |
| `manager` | optional | Everything except **Staff** |
| `cashier` | **required** — `/pos` reads stock from it | **Point of Sale** only |

## Permissions

Defined in `App\Enums\Permission`, one case per feature area:

| Key | Screen | Admin | Manager | Cashier |
|---|---|:-:|:-:|:-:|
| `pos` | Point of Sale | ✓ | ✓ | ✓ |
| `orders` | Order History | ✓ | ✓ | — |
| `debts` | In Debt | ✓ | ✓ | — |
| `consumption` | Myself | ✓ | ✓ | — |
| `reports` | Reports | ✓ | ✓ | — |
| `products` | Products | ✓ | ✓ | — |
| `categories` | Categories | ✓ | ✓ | — |
| `inventory` | Inventory | ✓ | ✓ | — |
| `customers` | Customers | ✓ | ✓ | — |
| `users` | Staff | ✓ | — | — |
| `stores` | Stores & registers | ✓ | ✓ | — |

Dashboard and the public `/menu` need no permission. Shop settings (`/settings/shop`) are deliberately **role-gated to admin**, not permission-gated — they change what every screen shows.

## How a check resolves

`User::hasPermission(Permission $p)` answers every question, in this order:

1. **Admin?** → always `true`.
2. **Override present** in the `users.permissions` JSON column (`{"reports": true}`)? → use it.
3. Otherwise → the role's default from `Permission::defaultFor(Role $r)`.

`NULL` in the column means "no overrides" — the account behaves exactly as its role. This is why adding a permission case never requires a data migration.

## The three enforcement layers

Access is enforced in three places, and **all three must agree**. The UI layer is convenience; the server layers are the security.

1. **Route middleware** — `->middleware('permission:reports')` ([EnsurePermission](../app/Http/Middleware/EnsurePermission.php)) blocks the request with 403 before the controller runs. Every feature route group in [routes/web.php](../routes/web.php) carries one.
2. **Policies** — decide *what kind* of action inside an area (`create`/`update`/`delete` vs read). They call `$user->hasPermission(...)`, never `isManager()`. Every policy keeps the `before()` admin bypass.
3. **Navigation** — the sidebar and phone tab bar render from `auth.can` (shared in [HandleInertiaRequests](../app/Http/Middleware/HandleInertiaRequests.php)); each nav item names its key via `requires:` in [navigation.ts](../resources/js/lib/navigation.ts). A user never sees a door they cannot open.

## Pattern: adding a new feature area

Follow this checklist top to bottom — each step is one file:

1. **Enum case** in `App\Enums\Permission` with `label()`, `group()`, and a sensible `defaultFor()` per role.
2. **Routes**: wrap the feature's routes in `Route::middleware('permission:<key>')->group(...)`.
3. **Policy** (if the feature has models): write checks as `$user->hasPermission(Permission::X)`; keep the `before()` admin bypass.
4. **Nav item** in `navigation.ts` with `requires: '<key>'`.

That's all — the Staff dialog, the shared `auth.can` map, and the middleware pick the new key up automatically from the enum.

## Pattern: checking access in code

```php
// Server — always through the enum:
$user->hasPermission(Permission::Reports)

// Blade/controller-adjacent role questions that are genuinely about the role
// (not a feature): $user->isAdmin(), $user->hasRole(Role::Manager)
```

```ts
// Vue — from the shared props:
const page = usePage<SharedData>();
page.props.auth.can.reports; // boolean, already resolved server-side
```

## What to avoid

- **Never gate a feature by role.** `$user->isManager()` in a policy or `role:admin,manager` on a feature route silently ignores per-user overrides — a granted cashier stays blocked and a revoked manager stays allowed. Role checks are only for questions that are *really* about the role (admin-only shop settings, the cashier store requirement).
- **Never enforce only in the UI.** Hiding a sidebar item or a button without the route middleware and policy behind it isn't access control — anyone can type the URL. Add the server gate first, the UI hint second.
- **Never let non-admins touch the permission machinery.** Only admins may edit `permissions` or create admin accounts; both are enforced server-side ([UserRequest](../app/Http/Requests/UserRequest.php), [UserController](../app/Http/Controllers/UserController.php)). Don't "simplify" this — it is the wall against self-escalation through a colleague's account.
- **Never write the `permissions` column by hand.** Go through the Staff dialog or `User::update(['permissions' => ...])` with keys from `Permission::values()`. Unknown keys are dropped on save; hand-written JSON with stale keys just rots.
- **Never special-case the admin outside `before()`/`hasPermission()`.** The admin bypass lives in exactly two places. Scattering `if ($user->isAdmin())` through controllers creates paths that disagree with the policy layer.
- **Don't add a permission for something that isn't a feature area.** Fine-grained rules ("may edit but not delete") belong in the policy for that model, not as new enum cases — the enum is the menu the Staff dialog renders, and it should stay short enough to read at a glance.
- **Don't remove enum cases casually.** Stored overrides referencing a removed key are ignored harmlessly, but renaming a key orphans existing grants — if you must rename, migrate the JSON column.

## Security invariants (keep these true)

- `EnsureRole` (bare, on the authenticated group) enforces `is_active` on every request — a deactivated user is out on their next click, mid-session.
- You cannot demote, deactivate, or delete **yourself**.
- Non-admins cannot edit or delete **admin accounts**, and cannot mint new admins.
- A user with sales history is deactivated instead of deleted, so order history keeps its cashier.
