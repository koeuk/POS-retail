# Multi-vendor — status and remaining plan

What is built, and what is left to do, for turning POS Retail into a multi-vendor system.

## Done

- **Vendors screen** (`/vendors`, permission `vendors`): an admin creates a vendor together with its login (email + password), its first store and a register. Per-vendor summary: products, stock on hand, stock value, sales over 7/30/90 days.
- **Data separation** ([app/Support/Tenant.php](../app/Support/Tenant.php)): each vendor sees only its own stores, products, categories, customers, stock, orders, debts and reports. `vendor_id IS NULL` is the admin's own shop. Admins see everything, or narrow it with the **Viewing** switcher in the header.
- **Vendor role**: full actions on its own data, and it may create cashiers for its own stores.
- **Per-vendor public menu**: `/menu?vendor=<uuid>`.
- **Roles** are now `superadmin`, `admin`, `vendor` and `cashier`. The migration `2026_09_26_130000_add_superadmin_role` turns existing admins into superadmins, and managers into cashiers that keep their old access as overrides.

## To do next

### 1. Finish the role change (superadmin / admin)

The code is changed but the test suite has not been run since, and some tests still use the removed `manager` role.

- [ ] Update tests that use `manager`: `InventoryTest`, `ActivityLogTest`, `UserPermissionsTest`, `RoleAccessTest`, `QrPaymentTest`, `VendorTest`, `TenantIsolationTest`.
- [ ] Tests that edit permissions, shop settings or payment settings as `admin()` now need `superadmin()`.
- [ ] Add tests: an admin cannot create or edit an admin or superadmin; only the superadmin edits permissions and settings.
- [ ] Run the full suite: `php artisan test`.
- [ ] Update [roles-and-permissions.md](roles-and-permissions.md): the roles table, the permission matrix and the "Only admins may edit permissions" wording still describe `manager`/`admin`.
- [ ] `DemoSeeder` / `DatabaseSeeder`: check the demo logins still make sense (the `manager@gmail.com` seed account was removed).

### 2. Per-vendor shop settings

Settings are still global and superadmin-only. Each vendor should have its own:

- [ ] Receipt header and footer, and the shop name shown on its menu and receipts.
- [ ] Order number code (Settings → Shop).
- [ ] Logo.
- [ ] Decide whether the currency stays global.

### 3. Per-vendor payments (KHQR)

A QR payment at a vendor's till still pays into the admin's Bakong account.

- [ ] A payment account per vendor (Settings → Payments, for the vendor's own login).
- [ ] QR charges use the account of the store's vendor.

### 4. Catalogue details

- [ ] SKU and barcode are unique across the whole system. Make them unique per vendor, so two vendors can use the same code and a clash does not reveal another vendor's product.
- [ ] A product's category must belong to the same vendor. This is enforced for vendors but not when an admin assigns one.

### 5. Admin tools

- [ ] Vendor summary: a copy button and a QR code for the vendor's menu link.
- [ ] Moving an existing store, product or customer from the admin's shop to a vendor.
- [ ] Activity log filtered by vendor.

### 6. Later (optional)

- [ ] A customer login role (see their own orders and debts), if needed.
