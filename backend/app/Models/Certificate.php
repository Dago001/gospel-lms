<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certificate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['verification_code'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'show_grade' => 'boolean', 'grade_percent' => 'float'];
    }

    public function revocations(): HasMany
    {
        return $this->hasMany(CertificateRevocation::class);
    }
}
