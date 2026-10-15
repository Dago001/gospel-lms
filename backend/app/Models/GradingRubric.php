<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingRubric extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class, 'rubric_id')->orderBy('position');
    }
}
