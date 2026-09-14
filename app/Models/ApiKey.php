<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The authenticatable entity for the API. There is no login/session concept
 * in this system - a merchant's integration calls the API with a bearer key
 * (X-API-Key header), and this model IS that key's identity. It is resolved
 * via Auth::viaRequest() (see AppServiceProvider), a native Laravel
 * mechanism built for exactly this "token in a header" case, so no
 * additional auth package is needed.
 */
class ApiKey extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory;

    protected $fillable = ['merchant_id', 'name', 'key_hash', 'last_used_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Generate a new plaintext key + its persisted hash. The plaintext is
     * only ever available at creation time (e.g. seeding), never stored.
     *
     * @return array{key: string, hash: string}
     */
    public static function generatePlaintextAndHash(): array
    {
        $plaintext = 'sk_'.Str::random(40);

        return [
            'key' => $plaintext,
            'hash' => hash('sha256', $plaintext),
        ];
    }

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
