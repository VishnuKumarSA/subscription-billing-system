<?php

namespace App\Jobs;

use App\Actions\Billing\GenerateInvoiceAction;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scoped to exactly one subscription. Idempotent by construction (see
 * GenerateInvoiceAction: row lock + the invoices unique constraint), so a
 * retried job or a duplicated dispatch cannot create a second invoice for
 * the same cycle.
 */
class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $subscriptionId) {}

    public function handle(GenerateInvoiceAction $action): void
    {
        $subscription = Subscription::findOrFail($this->subscriptionId);

        $action->execute($subscription);
    }
}
