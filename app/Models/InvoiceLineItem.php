<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'segment_id',
        'plan_id',
        'plan_name_snapshot',
        'segment_starts_on',
        'segment_ends_on',
        'day_fraction',
        'base_price_cents_snapshot',
        'prorated_base_cents',
        'included_units_snapshot',
        'prorated_included_units',
        'usage_units',
        'overage_units',
        'overage_rate_micros_snapshot',
        'overage_amount_cents',
        'line_total_cents',
    ];

    protected function casts(): array
    {
        return [
            'segment_starts_on' => 'date',
            'segment_ends_on' => 'date',
            'day_fraction' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlanSegment::class, 'segment_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
