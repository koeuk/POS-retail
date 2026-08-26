import type { LucideIcon } from 'lucide-vue-next';

export type Role = 'admin' | 'manager' | 'cashier';

export interface Auth {
    user: User | null;
    /** The shop this person is bound to; null for admins, who see them all. */
    store_name: string | null;
    can: {
        accessAdmin: boolean;
        manage: boolean;
        isAdmin: boolean;
    };
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Hide from users without this capability. */
    requires?: 'manage' | 'isAdmin';
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

export interface SharedData {
    /* Inertia's usePage<T>() constrains T to PageProps, which requires an
       index signature. Without it every usePage<SharedData>() call fails. */
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success: string | null; error: string | null };
    /** The shop's display currency; stored prices are always USD. */
    currency: { code: string; symbol: string; decimals: number; riel_per_usd: number };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: Role;
    store_id: number | null;
    is_active: boolean;
    email_verified_at?: string | null;
    created_at?: string;
    updated_at?: string;
}

/* -------------------------------------------------------------------------- */
/* Domain                                                                      */
/* -------------------------------------------------------------------------- */

export interface Store {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    registers?: Register[];
    users_count?: number;
    orders_count?: number;
}

export interface Register {
    id: number;
    store_id: number;
    name: string;
    is_active: boolean;
}

export interface Category {
    id: number;
    name: string;
    products_count?: number;
}

export interface Product {
    id: number;
    category_id: number;
    /** Set when this row is a pack of another product — a case of the base unit. */
    parent_product_id?: number | null;
    parent?: Pick<Product, 'id' | 'name'> | null;
    /** Base units one of these contains. 1 for a base product. */
    units_per_pack?: number;
    packs_count?: number;
    category?: Pick<Category, 'id' | 'name'>;
    name: string;
    sku: string;
    barcode: string | null;
    description: string | null;
    cost_price: string;
    sell_price: string;
    image: string | null;
    unit: string;
    track_stock: boolean;
    is_active: boolean;
    /** Summed across stores by the index query. */
    stock_qty?: number | null;
}

export interface Stock {
    id: number;
    product_id: number;
    store_id: number;
    qty: number;
    low_stock_threshold: number | null;
    store?: Pick<Store, 'id' | 'name'>;
}

export interface Customer {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    loyalty_points: number;
    orders_count?: number;
    spent_total?: string | null;
}

/* -------------------------------------------------------------------------- */
/* Pagination                                                                  */
/* -------------------------------------------------------------------------- */

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

export type BreadcrumbItemType = BreadcrumbItem;
