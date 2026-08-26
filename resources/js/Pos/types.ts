export interface PosProduct {
    id: number;
    name: string;
    /** Set when this row is a pack of another product — a case of the base unit. */
    parent_product_id: number | null;
    /** Base units one of these contains. 1 for a base product. */
    units_per_pack: number;
    sku: string;
    barcode: string | null;
    category_id: number;
    category_name: string | null;
    sell_price: string;
    unit: string;
    image: string | null;
    track_stock: boolean;
    /**
     * A hint for the cashier, in units of THIS row: for a case of 24 it is how
     * many whole cases the loose count covers. Stock is only ever decided
     * server-side at sync.
     */
    stock_qty: number;
}

export interface CartLine {
    productId: number;
    name: string;
    unitPrice: number;
    qty: number;
    discount: number;
    unit: string;
    trackStock: boolean;
    stockHint: number;
}

export type PaymentMethod = 'cash' | 'card' | 'qr' | 'credit';

export interface PosSettings {
    receipt_header: string;
    receipt_footer: string | null;
    currency_symbol: string;
}

export interface PosRegister {
    id: number;
    name: string;
}

export interface PosCategory {
    id: number;
    name: string;
}

export interface PosFeed {
    store_id: number;
    synced_at: string;
    products: PosProduct[];
    categories: PosCategory[];
    registers: PosRegister[];
    settings: PosSettings;
}

/** Exactly the shape POST /pos/data/orders/sync expects. */
export interface QueuedOrder {
    client_uuid: string;
    store_id: number;
    register_id: number | null;
    customer_id: number | null;
    created_offline_at: string;
    discount_amount: string;
    items: {
        product_id: number;
        product_name: string;
        qty: number;
        unit_price: string;
        discount: string;
    }[];
    payments: {
        method: PaymentMethod;
        amount: string;
        reference_no: string | null;
    }[];
}

export type SyncState = 'pending_sync' | 'synced' | 'failed';

export interface StoredOrder extends QueuedOrder {
    state: SyncState;
    /** Server figures, filled in once the order syncs. */
    order_no: string | null;
    total: string;
    attempts: number;
    last_error: string | null;
    receipt: {
        subtotal: string;
        total: string;
        paid: string;
        change: string;
        cashier: string;
        store: string;
    };
}
