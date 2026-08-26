<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Setting::put('currency', 'USD');
        Setting::put('riel_per_usd', '4100');
    }

    /* ------------------------------------------------------------------ */
    /* Conversion */
    /* ------------------------------------------------------------------ */

    public function test_usd_is_the_identity_and_never_disturbs_the_stored_value(): void
    {
        $usd = Currency::make('USD');

        $this->assertSame('$4.00', $usd->format('4.00'));
        $this->assertSame('$1,234.56', $usd->format(1234.56));
        $this->assertSame(4.0, $usd->convert('4.00'));
    }

    /**
     * Riel has no fractional unit. A converted price must land on a whole
     * riel, and the thousands separator matters — ៛16,400 reads, ៛16400 does not.
     */
    public function test_riel_converts_at_the_rate_and_rounds_to_whole_riel(): void
    {
        $khr = Currency::make('KHR');

        $this->assertSame('៛16,400', $khr->format('4.00'));
        $this->assertSame(16400.0, $khr->convert('4.00'));

        // 0.35 × 4100 = 1435 exactly; 0.33 × 4100 = 1353 — no decimals survive.
        $this->assertSame('៛1,435', $khr->format('0.35'));
        $this->assertSame('៛1,353', $khr->format('0.33'));
    }

    public function test_the_rate_comes_from_settings(): void
    {
        Setting::put('riel_per_usd', '4000');

        $this->assertSame('៛4,000', Currency::make('KHR')->format('1.00'));
    }

    /** A stale or hand-edited setting must not crash every page that formats a price. */
    public function test_an_unknown_currency_code_falls_back_to_usd(): void
    {
        Setting::put('currency', 'XYZ');

        $this->assertSame('USD', Currency::current()->code);
    }

    /* ------------------------------------------------------------------ */
    /* The setting drives the whole app */
    /* ------------------------------------------------------------------ */

    public function test_switching_the_setting_flips_what_every_page_receives(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.code', 'USD')
                ->where('currency.symbol', '$')
                ->where('currency.decimals', 2)
            );

        Setting::put('currency', 'KHR');

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.code', 'KHR')
                ->where('currency.symbol', '៛')
                ->where('currency.decimals', 0)
                ->where('currency.riel_per_usd', 4100)
            );
    }

    public function test_the_pos_feed_and_public_menu_carry_the_currency(): void
    {
        Setting::put('currency', 'KHR');

        $this->actingAs($this->admin)
            ->getJson(route('pos.data.products'))
            ->assertOk()
            ->assertJsonPath('settings.currency.code', 'KHR');

        $this->get(route('menu'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shop.currency.code', 'KHR'));
    }

    /* ------------------------------------------------------------------ */
    /* The settings screen */
    /* ------------------------------------------------------------------ */

    public function test_an_admin_can_change_the_currency_and_rate(): void
    {
        $this->actingAs($this->admin)
            ->put(route('shop.update'), [
                'receipt_header' => 'My Shop',
                'receipt_footer' => 'Come again',
                'currency' => 'KHR',
                'riel_per_usd' => 4050,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('KHR', Setting::get('currency'));
        $this->assertSame('4050', Setting::get('riel_per_usd'));
        $this->assertSame('My Shop', Setting::get('receipt_header'));
    }

    /** A rate of 0 would make every riel price ៛0; a rate of a million is a typo. */
    public function test_an_absurd_rate_is_rejected(): void
    {
        foreach ([0, -1, 1000000, 'abc'] as $bad) {
            $this->actingAs($this->admin)
                ->put(route('shop.update'), [
                    'receipt_header' => 'Shop',
                    'currency' => 'USD',
                    'riel_per_usd' => $bad,
                ])
                ->assertSessionHasErrors('riel_per_usd');
        }

        $this->assertSame('4100', Setting::get('riel_per_usd'), 'the stored rate must be untouched');
    }

    public function test_an_unsupported_currency_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('shop.update'), ['receipt_header' => 'Shop', 'currency' => 'EUR', 'riel_per_usd' => 4100])
            ->assertSessionHasErrors('currency');
    }

    /** Shop settings change what every cashier sees — a manager must not reach them. */
    public function test_only_an_admin_can_reach_shop_settings(): void
    {
        $store = Store::factory()->create();
        $manager = User::factory()->manager($store)->create();

        $this->actingAs($manager)->get(route('shop.edit'))->assertForbidden();
        $this->actingAs($manager)
            ->put(route('shop.update'), ['receipt_header' => 'x', 'currency' => 'KHR', 'riel_per_usd' => 4100])
            ->assertForbidden();

        $this->assertSame('USD', Setting::get('currency'), 'nothing changed');
        $this->actingAs($this->admin)->get(route('shop.edit'))->assertOk();
    }
}
