<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    use HasUuids;

    /** JSON columns have no database default on MySQL, so the empty-array default lives here. */
    protected $attributes = ['covered_ranges' => '[]'];

    protected $table = 'lesson_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['covered_ranges' => 'array', 'covered_seconds' => 'float', 'last_position_seconds' => 'float', 'percent' => 'float', 'completed_at' => 'datetime'];
    }
}
