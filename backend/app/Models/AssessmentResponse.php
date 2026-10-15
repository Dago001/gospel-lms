<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssessmentResponse extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answer' => 'array', 'is_correct' => 'boolean', 'auto_points' => 'float', 'manual_points' => 'float', 'saved_at' => 'datetime'];
    }

    public function points(): ?float
    {
        return $this->manual_points ?? $this->auto_points;
    }
}
