<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GradeChangeLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'grade_change_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['previous_score' => 'float', 'new_score' => 'float', 'created_at' => 'datetime'];
    }
}
