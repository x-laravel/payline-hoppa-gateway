<?php

namespace XLaravel\PaylineHoppaDriver\Tests\Feature;

use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Enums\CardType;
use XLaravel\PaylineHoppaDriver\HoppaBinLookupProvider;
use XLaravel\PaylineHoppaDriver\Tests\TestCase;

class HoppaBinLookupProviderTest extends TestCase
{
    private HoppaBinLookupProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new HoppaBinLookupProvider('https://api.hoppa.com');
    }

    public function test_lookup_returns_card_profile_for_credit_card(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Name'   => 'IS BANK',
                'Bank_Brand'  => 'MASTERCARD',
                'Card_Type'   => 'CREDIT',
                'Card_Family' => 'Maximum',
                'Card_Kind'   => 'BİREYSEL KART',
            ]),
        ]);

        $profile = $this->provider->lookup('51015200');

        $this->assertNotNull($profile);
        $this->assertSame('maximum', $profile->family);
        $this->assertSame(CardType::Credit, $profile->type);
    }

    public function test_lookup_returns_debit_card_type_for_debit(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Name'   => 'YAPI KREDİ',
                'Bank_Brand'  => 'VISA',
                'Card_Type'   => 'DEBIT',
                'Card_Family' => 'Paraf',
                'Card_Kind'   => 'DEBİT KART',
            ]),
        ]);

        $profile = $this->provider->lookup('45218200');

        $this->assertNotNull($profile);
        $this->assertSame('paraf', $profile->family);
        $this->assertSame(CardType::Debit, $profile->type);
    }

    public function test_lookup_normalises_the_family_the_rate_service_also_reports(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Card_Type' => 'CREDIT',
                'Card_Family' => '  Bonus ',
            ]),
        ]);

        $this->assertSame('bonus', $this->provider->lookup('51015200')->family);
    }

    public function test_lookup_sends_first_8_digits(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Name'   => 'AKBANK',
                'Bank_Brand'  => 'VISA',
                'Card_Type'   => 'CREDIT',
                'Card_Family' => 'Axess',
                'Card_Kind'   => 'BİREYSEL KART',
            ]),
        ]);

        $this->provider->lookup('45218299');

        Http::assertSent(fn ($r) => $r->data()['CardNumber'] === '45218299');
    }

    public function test_lookup_returns_null_when_card_family_missing(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([]),
        ]);

        $profile = $this->provider->lookup('51015200');

        $this->assertNull($profile);
    }

    public function test_lookup_returns_null_on_empty_response(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response(null),
        ]);

        $profile = $this->provider->lookup('51015200');

        $this->assertNull($profile);
    }

    public function test_bin_lookup_provider_registered_in_manager(): void
    {
        $this->app['config']->set('payline.bin_lookup.default', 'hoppa');

        $profile = app('payline.bin_lookup');

        $this->assertInstanceOf(\XLaravel\Payline\BinLookupManager::class, $profile);
    }
}