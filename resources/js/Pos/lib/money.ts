/**
 * Client-side twin of App\Services\OrderTotals.
 *
 * These two implementations MUST agree to the cent. The cashier hands over a
 * printed receipt at the till; the server recomputes the same sale hours later
 * when the queue flushes. If the algorithms drift, the paper and the database
 * disagree and nobody can tell which one is right.
 *
 * Same two steps as the server:
 *   1. the line discount comes off each line
 *   2. the order discount comes off the sum
 *
 * No tax: a price is what the customer pays. All arithmetic is in integer
 * cents — floats drift, and a till adds thousands of lines a day.
 */

export interface TotalsLine {
    qty: number;
    unitPrice: number;
    discount: number;
}

export interface Totals {
    subtotal: number;
    discount: number;
    total: number;
    lineSubtotals: number[];
}

export const toCents = (value: number | string | null | undefined): number => Math.round(Number(value ?? 0) * 100);

export const fromCents = (cents: number): number => cents / 100;

export function computeTotals(lines: TotalsLine[], orderDiscount = 0): Totals {
    const nets = lines.map((line) => Math.max(0, toCents(line.unitPrice) * line.qty - toCents(line.discount)));

    const subtotal = nets.reduce((sum, n) => sum + n, 0);

    // Never below zero, however large the number typed into the discount box.
    const discount = Math.min(toCents(orderDiscount), subtotal);

    return {
        subtotal: fromCents(subtotal),
        discount: fromCents(discount),
        total: fromCents(subtotal - discount),
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
