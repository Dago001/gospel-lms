<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['question_set' => 'array', 'started_at' => 'datetime', 'deadline_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime', 'results_published_at' => 'datetime',
            'auto_submitted' => 'boolean', 'passed' => 'boolean', 'max_score' => 'float', 'total_score' => 'float', 'percent' => 'float'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class, 'attempt_id');
    }
}
