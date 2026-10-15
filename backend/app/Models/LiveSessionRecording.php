<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LiveSessionRecording extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['play_url'];

    public function session(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }
}
