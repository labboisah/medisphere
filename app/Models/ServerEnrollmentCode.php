<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ServerEnrollmentCode extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['encrypted_code'];

    protected function casts(): array
    {
        return [
            'encrypted_code' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }
}
