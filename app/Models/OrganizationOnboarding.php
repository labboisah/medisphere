<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationOnboarding extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['organization_id', 'accepted_by', 'accepted_at', 'notice_version', 'authority_confirmed', 'processing_consent'];

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    protected function casts(): array
    {
        return [
            'authority_confirmed' => 'boolean',
            'processing_consent' => 'boolean',
            'accepted_at' => 'datetime',
        ];
    }
}
