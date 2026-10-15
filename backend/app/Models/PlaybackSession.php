<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackSession extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_event_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime', 'last_position' => 'float', 'credited_seconds' => 'float'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function isUsable(): bool
    {
        $idle = config('video.session_idle_minutes');

        return ! $this->ended_at && $this->expires_at->isFuture() && ($this->last_event_at ?? $this->started_at)->gt(now()->subMinutes($idle));
    }
}
