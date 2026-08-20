<?php

namespace App\Services;

/**
 * Money arithmetic for a sale, done entirely in integer cents.
 *
 * Floats drift: 0.1 + 0.2 is not 0.3, and a POS adds thousands of lines a day.
 * Everything here converts to cents on the way in and back to a 2dp string on
 * the way out, so a total can never be a fraction of a cent off.
 *
 * Order of operations, fixed by the build plan:
 *   1. line discount comes off the line
 *   2. the order-level discount is spread across lines in proportion to value
 *   3. tax is charged per line, on the discounted base, at that line's own rate
 *
 * Step 2 matters because lines can carry different tax rates. Applying an
 * order discount to the total instead would silently change how much tax is
 * owed on each rate band.
 */
class OrderTotals
{
    /** @var array<int, array{net: int, rate: float, taxable: int, tax: int}> */
    private array $lines = [];

    private int $subtotal = 0;

    private int $orderDiscount = 0;

    private int $taxAmount = 0;

    /**
     * @param  array<int, array{qty: int, unit_price: string|float, discount: string|float, tax_rate: string|float|null}>  $items
     */
    public function __construct(array $items, string|float $orderDiscount = 0)
    {
        foreach ($items as $item) {
            $gross = self::toCents($item['unit_price']) * (int) $item['qty'];
            $net = max(0, $gross - self::toCents($item['discount'] ?? 0));

            $this->lines[] = [
                'net' => $net,
                'rate' => (float) ($item['tax_rate'] ?? 0),
                'taxable' => $net,
                'tax' => 0,
            ];

            $this->subtotal += $net;
        }

        // An order discount cannot exceed the subtotal — a sale never goes
        // negative just because someone typed too large a number.
        $this->orderDiscount = min(self::toCents($orderDiscount), $this->subtotal);

        $this->allocateDiscount();
        $this->chargeTax();
    }

    /**
     * Spread the order discount across lines in proportion to their value,
     * giving any rounding remainder to the largest line so the parts always
     * add back up to the whole.
     */
    private function allocateDiscount(): void
    {
        if ($this->orderDiscount <= 0 || $this->subtotal <= 0) {
            return;
        }

        $allocated = 0;
        $largest = 0;

        foreach ($this->lines as $i => $line) {
            $share = (int) floor($this->orderDiscount * $line['net'] / $this->subtotal);
            $this->lines[$i]['taxable'] = $line['net'] - $share;
            $allocated += $share;

            if ($line['net'] > $this->lines[$largest]['net']) {
                $largest = $i;
            }
        }

        $remainder = $this->orderDiscount - $allocated;

        if ($remainder > 0) {
            $this->lines[$largest]['taxable'] = max(0, $this->lines[$largest]['taxable'] - $remainder);
        }
    }

    private function chargeTax(): void
    {
        foreach ($this->lines as $i => $line) {
            $tax = (int) round($line['taxable'] * $line['rate'] / 100);
            $this->lines[$i]['tax'] = $tax;
            $this->taxAmount += $tax;
        }
    }

    public function subtotal(): string
    {
        return self::toDecimal($this->subtotal);
    }

    public function discountAmount(): string
    {
        return self::toDecimal($this->orderDiscount);
    }

    public function taxAmount(): string
    {
        return self::toDecimal($this->taxAmount);
    }

    public function total(): string
    {
        return self::toDecimal($this->subtotal - $this->orderDiscount + $this->taxAmount);
    }

    /** Line net, before the order-level discount — what order_items.subtotal stores. */
    public function lineSubtotal(int $index): string
    {
        return self::toDecimal($this->lines[$index]['net'] ?? 0);
    }

    public static function toCents(string|float|int|null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    public static function toDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
