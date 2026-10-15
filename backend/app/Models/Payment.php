<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['access_code', 'verification'];

    protected function casts(): array
    {
        return ['verification' => 'array', 'paid_at' => 'datetime', 'last_verified_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['success', 'free'], true);
    }

    /** Refunds that count against the refundable balance (anything not definitively failed). */
    public function refundedMinor(): int
    {
        return (int) $this->refunds()->whereIn('status', ['requested', 'pending', 'processed', 'review'])->sum('amount_minor');
    }
}
