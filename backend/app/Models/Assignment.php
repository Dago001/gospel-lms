<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id', 'status', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'due_at' => 'datetime', 'late_cutoff_at' => 'datetime', 'published_at' => 'datetime', 'allowed_extensions' => 'array',
            'max_score' => 'float', 'pass_mark' => 'float', 'weight' => 'float', 'late_penalty_percent' => 'float', 'is_required' => 'boolean', 'allow_resubmission' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(GradingRubric::class, 'rubric_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}
