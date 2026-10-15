<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBank extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_id');
    }
}
