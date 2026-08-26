<?php

namespace App\Services;

/**
 * Money arithmetic for a sale, done entirely in integer cents.
 *
 * Floats drift: 0.1 + 0.2 is not 0.3, and a POS adds thousands of lines a day.
 * Everything here converts to cents on the way in and back to a 2dp string on
 * the way out, so a total can never be a fraction of a cent off.
 *
 * Two steps, in order:
 *   1. the line discount comes off each line
 *   2. the order-level discount comes off the sum
 *
 * There is no tax. Prices are what the customer pays; the total is simply the
 * lines less the discounts. This class used to spread the order discount across
 * lines so each tax band was charged correctly — with no bands to protect, that
 * allocation is gone and the discount comes straight off the subtotal.
 */
class OrderTotals
{
    /** @var list<int> line nets in cents, after the line discount */
    private array $lines = [];

    private int $subtotal = 0;

    private int $orderDiscount = 0;

    /**
     * @param  array<int, array{qty: int, unit_price: string|float, discount?: string|float|null}>  $items
     */
    public function __construct(array $items, string|float $orderDiscount = 0)
    {
        foreach ($items as $item) {
            $gross = self::toCents($item['unit_price']) * (int) $item['qty'];
            $net = max(0, $gross - self::toCents($item['discount'] ?? 0));

            $this->lines[] = $net;
            $this->subtotal += $net;
        }

        // An order discount cannot exceed the subtotal — a sale never goes
        // negative just because someone typed too large a number.
        $this->orderDiscount = min(self::toCents($orderDiscount), $this->subtotal);
    }

    public function subtotal(): string
    {
        return self::toDecimal($this->subtotal);
    }

    public function discountAmount(): string
    {
        return self::toDecimal($this->orderDiscount);
    }

    public function total(): string
    {
        return self::toDecimal($this->subtotal - $this->orderDiscount);
    }

    /** Line net, before the order-level discount — what order_items.subtotal stores. */
    public function lineSubtotal(int $index): string
    {
        return self::toDecimal($this->lines[$index] ?? 0);
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
