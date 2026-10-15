<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'version', 'created_by'];

    protected function casts(): array
    {
        return ['accepted_answers' => 'array', 'case_sensitive' => 'boolean', 'partial_credit' => 'boolean', 'points' => 'float', 'archived_at' => 'datetime'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }
}
