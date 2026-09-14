<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Denormalized from customers.merchant_id so every merchant-scoped
            // query/index on this table avoids a join through customers.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_plan_id')->constrained('plans');
            $table->string('status')->default('active'); // active | canceled
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->timestamps();

            // Serves the billing scheduler's exact predicate:
            // WHERE status = 'active' AND current_period_end <= ?
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
