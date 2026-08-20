/**
 * Client-side twin of App\Services\OrderTotals.
 *
 * These two implementations MUST agree to the cent. The cashier hands over a
 * printed receipt at the till; the server recomputes the same sale hours later
 * when the queue flushes. If the algorithms drift, the paper and the database
 * disagree and nobody can tell which one is right.
 *
 * Same rules as the server:
 *   1. line discount comes off the line
 *   2. the order discount is spread across lines in proportion to value
 *   3. tax is charged per line, on the discounted base, at that line's rate
 *
 * All arithmetic is in integer cents — floats drift, and a till adds thousands
 * of lines a day.
 */

export interface TotalsLine {
    qty: number;
    unitPrice: number;
    discount: number;
    taxRate: number;
}

export interface Totals {
    subtotal: number;
    discount: number;
    tax: number;
    total: number;
    lineSubtotals: number[];
}

export const toCents = (value: number | string | null | undefined): number => Math.round(Number(value ?? 0) * 100);

export const fromCents = (cents: number): number => cents / 100;

export function computeTotals(lines: TotalsLine[], orderDiscount = 0): Totals {
    const nets = lines.map((line) => Math.max(0, toCents(line.unitPrice) * line.qty - toCents(line.discount)));

    const subtotal = nets.reduce((sum, n) => sum + n, 0);
    const discount = Math.min(toCents(orderDiscount), subtotal);

    // Spread the order discount proportionally, giving the rounding remainder
    // to the largest line so the parts always add back up to the whole.
    const taxable = [...nets];

    if (discount > 0 && subtotal > 0) {
        let allocated = 0;
        let largest = 0;

        nets.forEach((net, i) => {
            const share = Math.floor((discount * net) / subtotal);
            taxable[i] = net - share;
            allocated += share;
            if (net > nets[largest]) largest = i;
        });

        const remainder = discount - allocated;
        if (remainder > 0) {
            taxable[largest] = Math.max(0, taxable[largest] - remainder);
        }
    }

    const tax = lines.reduce((sum, line, i) => sum + Math.round((taxable[i] * line.taxRate) / 100), 0);

    return {
        subtotal: fromCents(subtotal),
        discount: fromCents(discount),
        tax: fromCents(tax),
        total: fromCents(subtotal - discount + tax),
        lineSubtotals: nets.map(fromCents),
    };
}

export function formatMoney(value: number, symbol = '$'): string {
    return `${symbol}${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

/** Laravel decimal columns arrive as strings; keep the 2dp shape going back. */
export const toDecimalString = (value: number): string => value.toFixed(2);
