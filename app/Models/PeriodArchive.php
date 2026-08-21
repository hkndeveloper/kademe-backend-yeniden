<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PeriodArchive extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_id',
        'project_id',
        'closed_by',
        'closed_at',
        'archive_version',
        'schema_version',
        'previous_archive_id',
        'previous_hash',
        'summary_json',
        'warnings_json',
        'counts_json',
        'snapshot_json',
        'manifest_json',
        'readiness_json',
        'override_reason',
        'correction_reason',
        'integrity_hash',
        'verification_status',
        'verified_at',
        'verified_by',
        'notes',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'schema_version' => 'integer',
        'summary_json' => 'array',
        'warnings_json' => 'array',
        'counts_json' => 'array',
        'snapshot_json' => 'array',
        'manifest_json' => 'array',
        'readiness_json' => 'array',
        'verified_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function previousArchive()
    {
        return $this->belongsTo(PeriodArchive::class, 'previous_archive_id');
    }

    public function nextArchives()
    {
        return $this->hasMany(PeriodArchive::class, 'previous_archive_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Donem arsivi satirlari degistirilemez; yeni bir arsiv surumu olusturun.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Donem arsivi satirlari silinemez.');
    }
}
