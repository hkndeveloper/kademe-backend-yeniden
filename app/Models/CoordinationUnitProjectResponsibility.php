<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoordinationUnitProjectResponsibility extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'unit_id',
        'project_id',
        'service_domain',
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

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
