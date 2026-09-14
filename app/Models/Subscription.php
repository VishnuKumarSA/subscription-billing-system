<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'merchant_id',
        'current_plan_id',
        'status',
        'current_period_start',
        'current_period_end',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'date',
            'current_period_end' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(SubscriptionPlanSegment::class)->orderBy('starts_on');
    }

    public function openSegment(): ?SubscriptionPlanSegment
    {
        return $this->segments()->whereNull('ends_on')->first();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
