<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mid-cycle plan-change mechanism: a subscription has one row per
     * distinct plan/price it has ever been on. ends_on IS NULL marks the
     * currently active segment. Pricing is snapshotted here at the moment
     * the segment opens so a later Plan edit can never retroactively
     * change a bill that already happened.
     */
    public function up(): void
    {
        Schema::create('subscription_plan_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('base_price_cents_snapshot');
            $table->unsignedBigInteger('included_units_snapshot');
            $table->unsignedBigInteger('overage_rate_micros_snapshot');
            $table->char('currency_snapshot', 3);
            $table->timestamps();

            // Every query here is "this subscription's segment history, in
            // order" - finding the open segment, or finding segments that
            // overlap an invoice period. A subscription has a handful of
            // rows at most, so this single composite index covers it all.
            $table->index(['subscription_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_segments');
    }
};
