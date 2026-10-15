<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubmissionGrade extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw_score' => 'float', 'final_score' => 'float', 'penalty_percent' => 'float', 'criterion_scores' => 'array', 'published_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'submission_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(GradeChangeLog::class)->orderBy('created_at');
    }
}
