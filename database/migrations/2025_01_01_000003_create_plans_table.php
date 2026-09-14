<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Only 'monthly' is exercised by the billing engine today; the column
            // exists so additional cycles can be added without a schema change.
            $table->string('billing_cycle')->default('monthly');
            $table->unsignedInteger('base_price_cents');
            $table->unsignedBigInteger('included_units');
            // Overage rate stored in micros of the currency unit (1,000,000 micros = 1 unit)
            // so sub-cent per-unit rates (e.g. $0.0001/call) survive without precision loss.
            $table->unsignedBigInteger('overage_rate_micros');
            $table->char('currency', 3)->default('INR');
            $table->timestamps();

            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
