<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'legal_identity_reference', 'status'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return HasMany<Facility, $this>
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasOne<OrganizationOnboarding, $this> */
    public function onboarding(): HasOne
    {
        return $this->hasOne(OrganizationOnboarding::class);
    }

    /**
     * @return HasOne<KernelBridgeAccountLink, $this>
     */
    public function kernelBridgeAccountLink(): HasOne
    {
        return $this->hasOne(KernelBridgeAccountLink::class);
    }

    /**
     * @return HasMany<ServerInstallation, $this>
     */
    public function serverInstallations(): HasMany
    {
        return $this->hasMany(ServerInstallation::class);
    }
}
