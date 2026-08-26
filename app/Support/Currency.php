<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The shop's display currency.
 *
 * Prices are stored in exactly one base currency (USD) and never change.
 * What changes is how they are *shown*: the `currency` setting picks dollars
 * or riel, and `riel_per_usd` converts on the way out. Flipping the setting
 * flips every price, total and receipt in the app without touching a row.
 *
 * Riel has no fractional unit — a price is ៛4,000, never ៛4,000.50 — so each
 * currency carries its own decimal count and the formatter rounds to it.
 */
final class Currency
{
    public const USD = 'USD';

    public const KHR = 'KHR';

    /** @var array<string, array{symbol: string, decimals: int, name: string}> */
    public const DEFINITIONS = [
        self::USD => ['symbol' => '$', 'decimals' => 2, 'name' => 'US Dollar'],
        self::KHR => ['symbol' => '៛', 'decimals' => 0, 'name' => 'Cambodian Riel'],
    ];

    /** A sane fallback for a fresh install; the real figure lives in settings. */
    public const DEFAULT_RATE = 4100.0;

    private function __construct(
        public readonly string $code,
        public readonly string $symbol,
        public readonly int $decimals,
        public readonly float $rielPerUsd,
    ) {}

    /** The currency the shop is currently set to display. */
    public static function current(): self
    {
        $code = Setting::get('currency', self::USD);

        // Guard against a stale or hand-edited row: anything unknown is USD.
        if (! array_key_exists($code, self::DEFINITIONS)) {
            $code = self::USD;
        }

        return self::make($code);
    }

    public static function make(string $code): self
    {
        $def = self::DEFINITIONS[$code] ?? self::DEFINITIONS[self::USD];

        return new self(
            code: $code,
            symbol: $def['symbol'],
            decimals: $def['decimals'],
            rielPerUsd: (float) (Setting::get('riel_per_usd') ?? self::DEFAULT_RATE),
        );
    }

    /**
     * Convert a stored USD amount into this currency, rounded to its decimals.
     * For USD this is the identity, so the stored value is never disturbed.
     */
    public function convert(string|float|int|null $usd): float
    {
        $amount = (float) ($usd ?? 0);

        if ($this->code === self::USD) {
            return round($amount, 2);
        }

        return round($amount * $this->rielPerUsd, $this->decimals);
    }

    /** "$4.00" or "៛16,400". */
    public function format(string|float|int|null $usd): string
    {
        return $this->symbol.number_format($this->convert($usd), $this->decimals);
    }

    /** What the frontend needs to format prices identically. */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'riel_per_usd' => $this->rielPerUsd,
        ];
    }
}
