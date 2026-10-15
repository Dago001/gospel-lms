<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name', 'description', 'sort_order'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
