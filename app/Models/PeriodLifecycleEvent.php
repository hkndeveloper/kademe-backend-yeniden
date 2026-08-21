<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PeriodLifecycleEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'period_id',
        'project_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'metadata_json',
        'created_at',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Donem yasam dongusu olaylari degistirilemez.');
        });

        static::deleting(function () {
            throw new LogicException('Donem yasam dongusu olaylari silinemez.');
        });
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
