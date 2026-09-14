<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Tests\TestCase;

class UsageEventTest extends TestCase
{
    public function test_records_a_valid_usage_event(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();

        $response = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => $customer->id,
            'usage_date' => '2026-09-10',
            'units' => 10,
            'idempotency_key' => 'usage-abc-123',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('usage_events', 1);
        $this->assertSame(10, UsageEvent::first()->units);
        // created_at is a DB-level default, not an Eloquent timestamp - the
        // response must reflect it, not show null on a fresh insert.
        $this->assertNotNull($response->json('data.created_at'));
    }

    public function test_a_retried_request_with_the_same_idempotency_key_does_not_double_count(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();

        $payload = [
            'customer_id' => $customer->id,
            'usage_date' => '2026-09-10',
            'units' => 10,
            'idempotency_key' => 'usage-retry-key',
        ];

        $first = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', $payload);
        $second = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', $payload);
        $third = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', $payload);

        $first->assertCreated();
        $second->assertCreated();
        $third->assertCreated();

        // All three responses describe the same underlying event.
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('id'), $third->json('id'));

        $this->assertDatabaseCount('usage_events', 1);
        $this->assertSame(10, UsageEvent::sum('units'));
    }

    public function test_the_same_idempotency_key_is_independent_per_customer(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customerA = Customer::factory()->for($apiKey->merchant)->create();
        $customerB = Customer::factory()->for($apiKey->merchant)->create();

        $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => $customerA->id,
            'usage_date' => '2026-09-10',
            'units' => 5,
            'idempotency_key' => 'shared-key',
        ])->assertCreated();

        $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => $customerB->id,
            'usage_date' => '2026-09-10',
            'units' => 7,
            'idempotency_key' => 'shared-key',
        ])->assertCreated();

        $this->assertDatabaseCount('usage_events', 2);
    }

    public function test_rejects_a_nonexistent_customer(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();

        $response = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => 999999,
            'usage_date' => '2026-09-10',
            'units' => 10,
            'idempotency_key' => 'usage-abc-123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('customer_id');
    }

    public function test_rejects_a_customer_belonging_to_another_merchant(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $otherMerchant = Merchant::factory()->create();
        $otherCustomer = Customer::factory()->for($otherMerchant)->create();

        $response = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => $otherCustomer->id,
            'usage_date' => '2026-09-10',
            'units' => 10,
            'idempotency_key' => 'usage-abc-123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('usage_events', 0);
    }

    public function test_rejects_invalid_input(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();

        $response = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => $customer->id,
            'usage_date' => '2026-09-10',
            // units missing
            'idempotency_key' => 'usage-abc-123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('units');
    }

    public function test_rejects_unauthenticated_requests(): void
    {
        $response = $this->postJson('/api/usage', [
            'customer_id' => 1,
            'usage_date' => '2026-09-10',
            'units' => 10,
            'idempotency_key' => 'usage-abc-123',
        ]);

        $response->assertUnauthorized();
    }

    /**
     * Regression test: a plain (non-JSON-accepting) client hitting an
     * unauthenticated API route must still get a clean 401, not a 500 from
     * Laravel's default guest redirect trying to resolve a nonexistent
     * 'login' route. postJson() above sends Accept: application/json and
     * would mask this; a bare post() reproduces what a plain curl call sees.
     */
    public function test_unauthenticated_requests_without_an_accept_header_still_get_a_clean_401(): void
    {
        $response = $this->post('/api/usage', [
            'customer_id' => 1,
            'usage_date' => '2026-09-10',
            'units' => 10,
            'idempotency_key' => 'usage-abc-123',
        ]);

        $response->assertUnauthorized();
    }

    public function test_the_usage_endpoint_is_rate_limited_per_api_key(): void
    {
        config(['billing.usage_rate_limit_per_minute' => 2]);

        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();

        $makeRequest = fn (string $key) => $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/usage', [
            'customer_id' => $customer->id,
            'usage_date' => '2026-09-10',
            'units' => 1,
            'idempotency_key' => $key,
        ]);

        $makeRequest('key-1')->assertCreated();
        $makeRequest('key-2')->assertCreated();
        $makeRequest('key-3')->assertStatus(429);
    }
}
