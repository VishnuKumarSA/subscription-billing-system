<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The high-volume table (designed for 50M+ rows). Kept deliberately
     * narrow: only two indexes exist here, and both map to a real query -
     * see README "Database design" for the reasoning. Nothing reads this
     * table in aggregate; usage_daily_aggregates exists for that.
     */
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            // Denormalized for tenant-scoped debugging/ops queries. Deliberately
            // NOT indexed - nothing should ever query this table merchant-wide.
            $table->foreignId('merchant_id')->constrained();
            $table->date('usage_date');
            $table->unsignedInteger('units');
            // Fixed-length hash rather than an arbitrary client string, so the
            // unique index below stays compact regardless of what the client sends.
            $table->char('idempotency_key', 64);
            // created_at only (no updated_at) - rows are immutable, and skipping
            // the second timestamp column saves a write on every high-volume insert.
            $table->timestamp('created_at')->useCurrent();

            // The idempotency guarantee itself: a retried request with the same
            // key for the same customer resolves to this existing row instead
            // of inserting a duplicate.
            $table->unique(['customer_id', 'idempotency_key']);

            // Serves the daily aggregation job's per-customer, date-bounded scan.
            // Scoped to one customer, so cost is independent of total table size.
            $table->index(['customer_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
