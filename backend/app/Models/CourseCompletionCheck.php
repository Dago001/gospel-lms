<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CourseCompletionCheck extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'reasons' => 'array', 'components' => 'array', 'grade_percent' => 'float', 'approved_at' => 'datetime', 'evaluated_at' => 'datetime'];
    }
}
