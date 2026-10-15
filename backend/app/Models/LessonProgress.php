<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LessonProgress extends Model
{
    use HasUuids;

    protected $table = 'lesson_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['covered_ranges' => 'array', 'covered_seconds' => 'float', 'last_position_seconds' => 'float', 'percent' => 'float', 'completed_at' => 'datetime'];
    }
}
