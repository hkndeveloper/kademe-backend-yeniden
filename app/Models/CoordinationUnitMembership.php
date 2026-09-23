<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoordinationUnitMembership extends Model
{
    use HasFactory, SoftDeletes;

    public const POSITION_COORDINATOR = 'coordinator';

    public const POSITION_STAFF = 'staff';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PASSIVE = 'passive';

    protected $fillable = [
        'unit_id',
        'user_id',
        'position',
        'is_primary',
        'status',
        'starts_at',
        'ends_at',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $builder) {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $builder) {
                $builder->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    public function unit()
    {
        return $this->belongsTo(CoordinationUnit::class, 'unit_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function permissionOverrides()
    {
        return $this->hasMany(CoordinationUnitMembershipPermissionOverride::class, 'membership_id');
    }
}
