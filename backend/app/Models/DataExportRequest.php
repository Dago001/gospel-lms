<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataExportRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'ready_at' => 'datetime', 'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'details' => 'array'];
    }
}
