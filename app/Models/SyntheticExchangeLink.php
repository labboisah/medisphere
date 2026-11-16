<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyntheticExchangeLink extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'facility_id',
        'local_subject_reference',
        'recipient_subject_reference',
        'review_status',
        'created_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<SyntheticSharingGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(SyntheticSharingGrant::class);
    }
}
