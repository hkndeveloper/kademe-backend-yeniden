<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoordinationUnitMembershipPermissionOverride extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PASSIVE = 'passive';

    protected $fillable = [
        'membership_id',
        'permission_name',
        'effect',
        'scope_type',
        'scope_payload',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['scope_payload' => 'array'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function membership()
    {
        return $this->belongsTo(CoordinationUnitMembership::class, 'membership_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
