<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RubricCriterion extends Model
{
    use HasUuids;

    protected $table = 'rubric_criteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['max_points' => 'float'];
    }
}
