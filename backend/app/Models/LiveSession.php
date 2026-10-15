<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveSession extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'created_by'];

    protected $hidden = ['host_url'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'counts_for_attendance' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(LiveSessionAttendance::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(LiveSessionRecording::class);
    }

    public function endsAt()
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    /** Minutes a learner must attend to be counted present. */
    public function requiredMinutes(): int
    {
        return $this->min_minutes_for_attendance > 0 ? $this->min_minutes_for_attendance : (int) ceil($this->duration_minutes * 0.75);
    }
}
