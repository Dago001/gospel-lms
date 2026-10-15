<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'created_by'];

    protected function casts(): array
    {
        return ['course_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean', 'value' => 'integer'];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
