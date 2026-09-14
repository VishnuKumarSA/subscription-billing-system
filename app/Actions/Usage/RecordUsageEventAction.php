<?php

namespace App\Actions\Usage;

use App\DTOs\UsageEventData;
use App\Models\UsageEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency is enforced by the database (unique index on
 * customer_id + idempotency_key), not by an application-level "check then
 * insert" - that pattern races under concurrent retries, since two
 * requests can both pass a SELECT check before either INSERT lands.
 *
 * Instead: attempt the insert; if it collides, fetch and return the row
 * that already exists. A retried request therefore looks identical to the
 * first successful call from the caller's point of view.
 */
class RecordUsageEventAction
{
    public function execute(UsageEventData $data): UsageEvent
    {
        $hashedKey = hash('sha256', $data->idempotencyKey);

        try {
            return DB::transaction(function () use ($data, $hashedKey) {
                $event = UsageEvent::create([
                    'customer_id' => $data->customerId,
                    'merchant_id' => $data->merchantId,
                    'usage_date' => $data->usageDate,
                    'units' => $data->units,
                    'idempotency_key' => $hashedKey,
                ]);

                // created_at is a DB-level default (useCurrent()), not an
                // Eloquent timestamp ($timestamps = false on UsageEvent), so
                // the in-memory model doesn't have it until refreshed - without
                // this, the API response for a fresh insert shows created_at
                // as null while the DB row genuinely has a value.
                return $event->refresh();
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            return UsageEvent::where('customer_id', $data->customerId)
                ->where('idempotency_key', $hashedKey)
                ->firstOrFail();
        }
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation, covers both
        // MySQL's 1062 (duplicate entry) and SQLite's UNIQUE constraint failure.
        return $e->getCode() === '23000';
    }
}
