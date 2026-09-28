<?php

namespace XLaravel\PaylineHoppaDriver;

use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;

class HoppaBinLookupProvider implements BinLookupProvider
{
    public function __construct(private readonly string $apiUrl) {}

    public function lookup(string $bin): ?CardProfile
    {
        $response = Http::post($this->apiUrl . '/api/services/EYVBinService', [
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
