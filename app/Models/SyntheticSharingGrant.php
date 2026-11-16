<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SyntheticSharingGrant extends Model
{
    use HasUuids;

    protected $fillable = [
        'synthetic_exchange_link_id',
        'organization_id',
        'facility_id',
        'purpose',
        'scope',
        'created_by',
        'expires_at',
        'revoked_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function getStatusAttribute(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'active';
    }
}
