<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail: every number that fed the invoice calculation is
     * captured here, not just the result, so a line item can be verified
     * by hand without recomputing anything.
     */
    public function up(): void
    {
        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('subscription_plan_segments')->nullOnDelete();
            $table->foreignId('plan_id')->constrained();
            // Denormalized on purpose: must survive the Plan later being renamed/deleted.
            $table->string('plan_name_snapshot');
            $table->date('segment_starts_on');
            $table->date('segment_ends_on')->nullable();
            $table->decimal('day_fraction', 9, 6);
            $table->unsignedInteger('base_price_cents_snapshot');
            $table->unsignedInteger('prorated_base_cents');
            $table->unsignedBigInteger('included_units_snapshot');
            $table->unsignedBigInteger('prorated_included_units');
            $table->unsignedBigInteger('usage_units');
            $table->unsignedBigInteger('overage_units');
            $table->unsignedBigInteger('overage_rate_micros_snapshot');
            $table->unsignedBigInteger('overage_amount_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
    }
};
