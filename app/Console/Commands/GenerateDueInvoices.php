<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoiceJob;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Billing scheduler entrypoint. Finds active subscriptions whose current
 * cycle has ended (served by the (status, current_period_end) index) and
 * dispatches one job per subscription, chunked so a merchant with a large
 * customer base is never loaded into memory at once.
 */
class GenerateDueInvoices extends Command
{
    protected $signature = 'billing:generate-due-invoices {date? : Treat this date as "now" (Y-m-d), defaults to today}';

    protected $description = 'Dispatch invoice-generation jobs for every subscription whose billing cycle has ended';

    public function handle(): int
    {
        $asOf = $this->argument('date')
            ? CarbonImmutable::parse($this->argument('date'))->toDateString()
            : CarbonImmutable::now()->toDateString();

        $chunkSize = (int) config('billing.invoice_chunk_size');
        $dispatched = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereDate('current_period_end', '<=', $asOf)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($subscriptions) use (&$dispatched) {
                foreach ($subscriptions as $subscription) {
                    GenerateInvoiceJob::dispatch($subscription->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} invoice-generation jobs.");

        return self::SUCCESS;
    }
}
