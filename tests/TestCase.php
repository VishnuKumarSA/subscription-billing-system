<?php

namespace Tests;

use App\Models\ApiKey;
use App\Models\Merchant;
use Database\Factories\ApiKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Create an API key for the given (or a new) merchant and return both
     * the model and its plaintext, ready to send as the X-API-Key header.
     *
     * @return array{0: ApiKey, 1: string}
     */
    protected function createApiKey(?Merchant $merchant = null): array
    {
        $merchant ??= Merchant::factory()->create();

        $apiKey = ApiKey::factory()->for($merchant)->create();

        return [$apiKey, ApiKeyFactory::$lastPlaintext];
    }

    protected function authHeaders(string $plaintextKey): array
    {
        return ['X-API-Key' => $plaintextKey];
    }
}
