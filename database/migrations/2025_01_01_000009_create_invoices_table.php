<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('merchant_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('finalized'); // draft | finalized
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->char('currency', 3);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            // Prevents a retried/duplicated invoice-generation dispatch from
            // ever creating a second invoice for the same billing cycle.
            $table->unique(['subscription_id', 'period_start', 'period_end']);
            $table->index(['merchant_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
