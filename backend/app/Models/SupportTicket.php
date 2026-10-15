<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;


class SupportTicket extends Model
{
    use HasUuids;


    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
