<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'type', 'content', 'video_source', 'youtube_id', 'duration_seconds', 'required_completion_percent', 'is_required', 'is_preview'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_preview' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    public function videoAsset(): BelongsTo
    {
        return $this->belongsTo(VideoAsset::class, 'video_asset_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class);
    }

    /** Server-side completion threshold: lesson override, else the course rule (default 90%). */
    public function requiredCompletionPercent(): int
    {
        return $this->required_completion_percent ?? $this->course->video_completion_percent ?? 90;
    }
}
