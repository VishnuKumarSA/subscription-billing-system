<?php

namespace Tests\Feature;

use App\Actions\Billing\GenerateInvoiceAction;
use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Billing\OverageCalculator;
use App\Billing\ProrationCalculator;
use App\Models\Customer;
use App\Models\Plan;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * IDOR / cross-tenant access coverage: an authenticated API key for
 * merchant A must never be able to read merchant B's resources, even when
 * it knows (or guesses) B's numeric IDs.
 */
class TenantIsolationTest extends TestCase
{
    public function test_cannot_view_another_merchants_plan(): void
    {
        [, $plaintextA] = $this->createApiKey();
        [$apiKeyB] = $this->createApiKey();
        $plan = Plan::factory()->for($apiKeyB->merchant)->create();

        $this->withHeaders($this->authHeaders($plaintextA))
            ->getJson("/api/plans/{$plan->id}")
            ->assertForbidden();
    }

    public function test_cannot_view_another_merchants_customer(): void
    {
        [, $plaintextA] = $this->createApiKey();
        [$apiKeyB] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKeyB->merchant)->create();

        $this->withHeaders($this->authHeaders($plaintextA))
            ->getJson("/api/customers/{$customer->id}")
            ->assertForbidden();
    }

    public function test_cannot_view_another_merchants_subscription(): void
    {
        [, $plaintextA] = $this->createApiKey();
        [$apiKeyB] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKeyB->merchant)->create();
        $plan = Plan::factory()->for($apiKeyB->merchant)->create();
        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::now());

        $this->withHeaders($this->authHeaders($plaintextA))
            ->getJson("/api/subscriptions/{$subscription->id}")
            ->assertForbidden();
    }

    public function test_cannot_change_the_plan_of_another_merchants_subscription(): void
    {
        [, $plaintextA] = $this->createApiKey();
        [$apiKeyB] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKeyB->merchant)->create();
        $plan = Plan::factory()->for($apiKeyB->merchant)->create();
        $otherPlan = Plan::factory()->for($apiKeyB->merchant)->create();
        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::now());

        $this->withHeaders($this->authHeaders($plaintextA))
            ->postJson("/api/subscriptions/{$subscription->id}/change-plan", ['new_plan_id' => $otherPlan->id])
            ->assertForbidden();
    }

    public function test_cannot_view_another_merchants_invoice(): void
    {
        [, $plaintextA] = $this->createApiKey();
        [$apiKeyB] = $this->createApiKey();
        $customer = Customer::factory()->for($apiKeyB->merchant)->create();
        $plan = Plan::factory()->for($apiKeyB->merchant)->create();
        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $invoice = (new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator))
            ->execute($subscription->fresh());

        $this->withHeaders($this->authHeaders($plaintextA))
            ->getJson("/api/invoices/{$invoice->id}")
            ->assertForbidden();
    }

    public function test_an_invalid_api_key_is_unauthenticated(): void
    {
        $this->withHeaders(['X-API-Key' => 'not-a-real-key'])
            ->getJson('/api/plans')
            ->assertUnauthorized();
    }
}
