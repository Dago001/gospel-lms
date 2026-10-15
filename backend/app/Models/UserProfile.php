<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'phone', 'bio', 'country', 'photo_path', 'timezone', 'headline', 'public_profile'];

    protected function casts(): array
    {
        return ['public_profile' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
