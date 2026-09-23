<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoordinationUnitPermissionRule extends Model
{
    use HasFactory, SoftDeletes;

    public const EFFECT_ALLOW = 'allow';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PASSIVE = 'passive';

    public const SCOPE_LINKED_PROJECT = 'linked_project';

    public const SCOPE_RESPONSIBILITY_PROJECTS = 'responsibility_projects';

    public const SCOPE_OWN_UNIT = 'own_unit';

    public const SCOPE_OWN_RECORD = 'own_record';

    public const SCOPE_SELF = 'self';

    public const SCOPE_ALL = 'all';

    public const SCOPE_NONE = 'none';

    protected $fillable = [
        'unit_id',
        'position',
        'permission_name',
        'effect',
        'scope_source',
        'service_domain',
        'scope_payload',
        'status',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_payload' => 'array',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
