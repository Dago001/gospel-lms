<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
