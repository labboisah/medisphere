<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KernelBridgeAccountLink extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
