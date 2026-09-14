<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rollup table that makes billing and the dashboard fast regardless
     * of how large usage_events grows. Bounded by customers x days, not by
     * raw event volume.
     */
    public function up(): void
    {
        Schema::create('usage_daily_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('merchant_id')->constrained();
            $table->date('usage_date');
            $table->unsignedBigInteger('units_total')->default(0);
            $table->timestamps();

            // Natural business key AND the idempotent-upsert target for the
            // aggregation job: rerunning it just re-upserts the same row.
            $table->unique(['customer_id', 'usage_date']);

            // Serves every dashboard query (top-5, churn MoM, trend) directly.
            $table->index(['merchant_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily_aggregates');
    }
};
