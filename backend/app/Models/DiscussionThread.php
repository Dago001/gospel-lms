<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiscussionThread extends Model
{
    use HasUuids, SoftDeletes;


    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'locked' => 'boolean', 'last_post_at' => 'datetime'];
    }

    public function author(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function posts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DiscussionPost::class, 'thread_id');
    }
}
