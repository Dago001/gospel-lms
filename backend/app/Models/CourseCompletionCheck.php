<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CourseCompletionCheck extends Model
{
    use HasUuids;

    /** JSON columns have no database default on MySQL, so the empty-array default lives here. */
    protected $attributes = ['reasons' => '[]', 'components' => '[]'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'reasons' => 'array', 'components' => 'array', 'grade_percent' => 'float', 'approved_at' => 'datetime', 'evaluated_at' => 'datetime'];
    }
}
