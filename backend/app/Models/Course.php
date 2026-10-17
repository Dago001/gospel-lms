<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /** JSON columns have no database default on MySQL, so the empty-array default lives here. */
    protected $attributes = ['objectives' => '[]', 'requirements' => '[]'];

    public const EDITABLE_STATUSES = ['draft', 'approved', 'published'];

    protected $guarded = ['id', 'slug', 'review_notes', 'scheduled_publish_at', 'status', 'approved_by', 'approved_at', 'published_at', 'archived_at', 'version', 'created_by', 'price_minor', 'currency', 'discount_price_minor', 'discount_starts_at', 'discount_ends_at'];

    protected function casts(): array
    {
        return [
            'objectives' => 'array', 'requirements' => 'array', 'completion_policy' => 'array',
            'approved_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime',
            'scheduled_publish_at' => 'datetime', 'discount_starts_at' => 'datetime', 'discount_ends_at' => 'datetime',
            'sequential_progression' => 'boolean', 'price_minor' => 'integer', 'discount_price_minor' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_instructors')->withPivot('role')->withTimestamps();
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'course_prerequisites', 'course_id', 'prerequisite_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('position');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CourseVersion::class)->orderByDesc('version');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isTaughtBy(User $user): bool
    {
        return $this->instructors()->where('users.id', $user->id)->exists();
    }

    public function durationMinutes(): int
    {
        return $this->duration_minutes_override
            ?? (int) ceil(($this->lessons()->sum('duration_seconds') ?: 0) / 60);
    }
}
