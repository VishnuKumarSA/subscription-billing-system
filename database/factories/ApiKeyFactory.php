<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApiKey> */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * The plaintext key generated for the most recently created instance -
     * tests read this immediately after ->create() to authenticate with it,
     * since the plaintext itself is never persisted.
     */
    public static ?string $lastPlaintext = null;

    public function definition(): array
    {
        $pair = ApiKey::generatePlaintextAndHash();
        static::$lastPlaintext = $pair['key'];

        return [
            'merchant_id' => Merchant::factory(),
            'name' => 'Test Key',
            'key_hash' => $pair['hash'],
            'last_used_at' => null,
        ];
    }
}
