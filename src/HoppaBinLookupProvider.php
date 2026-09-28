<?php

namespace XLaravel\Payline\Gateways\Hoppa;

use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;

class HoppaBinLookupProvider implements BinLookupProvider
{
    private const string TEST_BASE_URL = 'https://posservicetest.esnekpos.com';

    private const string LIVE_BASE_URL = 'https://posservice.esnekpos.com';

    private readonly string $baseUrl;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url']
            ?? (($config['test_mode'] ?? false) ? self::TEST_BASE_URL : self::LIVE_BASE_URL);
    }

    public function lookup(string $bin): ?CardProfile
    {
        $response = Http::post($this->baseUrl . '/api/services/EYVBinService', [
            'CardNumber' => substr($bin, 0, 8),
        ])->json();

        if (empty($response) || ! isset($response['Card_Family'])) {
            return null;
        }

        $cardType = strtoupper($response['Card_Type'] ?? '') === 'DEBIT'
            ? CardType::Debit
            : CardType::Credit;

        return new CardProfile(
            family: mb_strtolower(trim($response['Card_Family']), 'UTF-8'),
            type: $cardType,
        );
    }
}
