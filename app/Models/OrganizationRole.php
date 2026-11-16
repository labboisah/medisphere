<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrganizationRole extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function membershipRole(): string
    {
        return 'custom:'.$this->id;
    }
}
