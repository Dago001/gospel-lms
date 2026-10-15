<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id', 'status', 'version', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'published_at' => 'datetime', 'pools' => 'array', 'pass_mark_percent' => 'float', 'weight' => 'float',
            'is_required' => 'boolean', 'is_final_exam' => 'boolean', 'require_all_lessons' => 'boolean', 'shuffle_questions' => 'boolean', 'shuffle_options' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function fixedQuestions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'assessment_questions')->withPivot(['position', 'points'])->orderByPivot('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AssessmentAttempt::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'published' && (! $this->opens_at || $this->opens_at->lte(now())) && (! $this->closes_at || $this->closes_at->gte(now()));
    }
}
