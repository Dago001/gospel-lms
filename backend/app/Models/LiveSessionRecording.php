<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LiveSessionRecording extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['play_url'];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }
}
