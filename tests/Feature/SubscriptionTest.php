<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    public function test_creates_a_subscription_for_a_customer(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();
        $plan = Plan::factory()->for($apiKey->merchant)->create();

        $response = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/subscriptions', [
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'start_date' => '2026-09-01',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('subscriptions', ['customer_id' => $customer->id, 'status' => 'active']);
        $this->assertDatabaseCount('subscription_plan_segments', 1);
    }

    public function test_rejects_a_second_active_subscription_for_the_same_customer(): void
    {
        [$apiKey, $plaintext] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKey->merchant)->create();
        $plan = Plan::factory()->for($apiKey->merchant)->create();

        $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/subscriptions', [
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
        ])->assertCreated();

        $response = $this->withHeaders($this->authHeaders($plaintext))->postJson('/api/subscriptions', [
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('subscriptions', 1);
    }
}
