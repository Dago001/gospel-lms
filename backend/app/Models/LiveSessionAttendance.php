<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LiveSessionAttendance extends Model
{
    use HasUuids;

    protected $table = 'live_session_attendance';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['present' => 'boolean'];
    }
}
