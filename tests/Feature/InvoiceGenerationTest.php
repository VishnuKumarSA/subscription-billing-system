<?php

namespace Tests\Feature;

use App\Actions\Billing\GenerateInvoiceAction;
use App\Actions\Subscriptions\CreateSubscriptionAction;
use App\Billing\OverageCalculator;
use App\Billing\ProrationCalculator;
use App\Jobs\GenerateInvoiceJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\PlanPricingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InvoiceGenerationTest extends TestCase
{
    private function invoiceAction(): GenerateInvoiceAction
    {
        return new GenerateInvoiceAction(new ProrationCalculator, new OverageCalculator);
    }

    public function test_generates_one_invoice_with_a_correct_total(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create(['base_price_cents' => 300000]);

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $invoice = $this->invoiceAction()->execute($subscription->fresh());

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(300000, $invoice->total_cents);
        $this->assertSame('finalized', $invoice->status);
    }

    public function test_calling_the_action_twice_for_the_same_cycle_does_not_create_a_duplicate_invoice(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create();

        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $action = $this->invoiceAction();
        $first = $action->execute($subscription->fresh());

        // Simulate a retried/duplicated job dispatch for the ORIGINAL cycle
        // by resetting the subscription's period back before calling again.
        // A query-builder update is used deliberately: $subscription's
        // in-memory attributes were never touched by the action (which
        // mutated a separate locked instance), so forceFill()->save() would
        // see no dirty change and silently skip writing these columns.
        Subscription::whereKey($subscription->id)->update([
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);

        $second = $action->execute($subscription->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_the_database_rejects_a_duplicate_invoice_for_the_same_cycle_even_if_bypassing_the_action(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create();
        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        Invoice::create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-10-01',
            'status' => 'finalized',
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'INR',
            'generated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        Invoice::create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-10-01',
            'status' => 'finalized',
            'subtotal_cents' => 999,
            'total_cents' => 999,
            'currency' => 'INR',
            'generated_at' => now(),
        ]);
    }

    public function test_the_job_is_safe_to_run_twice(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create();
        $subscription = (new CreateSubscriptionAction(app(PlanPricingResolver::class)))
            ->execute($customer, $plan, CarbonImmutable::parse('2026-09-01'));

        $job = new GenerateInvoiceJob($subscription->id);
        $job->handle($this->invoiceAction());

        // Reset the period so the second run targets the same cycle again.
        Subscription::whereKey($subscription->id)->update([
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        $job->handle($this->invoiceAction());

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_the_scheduler_only_dispatches_for_subscriptions_whose_cycle_has_ended(): void
    {
        $merchant = Merchant::factory()->create();

        $dueCustomer = Customer::factory()->for($merchant)->create();
        $notDueCustomer = Customer::factory()->for($merchant)->create();
        $plan = Plan::factory()->for($merchant)->create();

        $resolver = app(PlanPricingResolver::class);
        $due = (new CreateSubscriptionAction($resolver))->execute($dueCustomer, $plan, CarbonImmutable::parse('2026-08-01'));
        $due->forceFill(['current_period_end' => '2026-09-01'])->save(); // ended yesterday relative to "today" below

        $notDue = (new CreateSubscriptionAction($resolver))->execute($notDueCustomer, $plan, CarbonImmutable::parse('2026-09-01'));

        Artisan::call('billing:generate-due-invoices', ['date' => '2026-09-05']);

        $this->assertDatabaseHas('invoices', ['subscription_id' => $due->id]);
        $this->assertDatabaseMissing('invoices', ['subscription_id' => $notDue->id]);
    }
}
