import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Mirror of App\Support\Currency. Stored amounts are always USD; this converts
 * and formats them for display in whatever the shop is set to show.
 */
export interface CurrencyDef {
    code: string;
    symbol: string;
    /** 2 for dollars, 0 for riel — riel has no fractional unit. */
    decimals: number;
    riel_per_usd: number;
}

export const USD: CurrencyDef = { code: 'USD', symbol: '$', decimals: 2, riel_per_usd: 4100 };

/** Convert a stored USD amount into `def`, rounded to that currency's decimals. */
export function convertAmount(usd: number | string | null | undefined, def: CurrencyDef): number {
    const amount = Number(usd ?? 0);
    if (!Number.isFinite(amount)) return 0;

    if (def.code === 'USD') return Math.round(amount * 100) / 100;

    const factor = 10 ** def.decimals;
    return Math.round(amount * def.riel_per_usd * factor) / factor;
}

/** "$4.00" or "៛16,400". The one place a price becomes a string. */
export function formatCurrency(usd: number | string | null | undefined, def: CurrencyDef): string {
    return (
        def.symbol +
        convertAmount(usd, def).toLocaleString(undefined, {
            minimumFractionDigits: def.decimals,
            maximumFractionDigits: def.decimals,
        })
    );
}

/**
 * The shop currency for the current page, from the Inertia shared props.
 * Pages that already carry a `currency` prop (POS, order detail, menu) can
 * pass it explicitly instead; the shape is the same.
 */
export function useCurrency(override?: () => CurrencyDef | undefined) {
    const page = usePage<SharedData>();

    const currency = computed<CurrencyDef>(() => override?.() ?? page.props.currency ?? USD);

    const money = (usd: number | string | null | undefined) => formatCurrency(usd, currency.value);
    const convert = (usd: number | string | null | undefined) => convertAmount(usd, currency.value);

    return { currency, money, convert };
}
