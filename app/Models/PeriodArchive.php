<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeriodArchive extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_id',
        'project_id',
        'closed_by',
        'closed_at',
        'archive_version',
        'summary_json',
        'warnings_json',
        'counts_json',
        'integrity_hash',
        'notes',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'summary_json' => 'array',
        'warnings_json' => 'array',
        'counts_json' => 'array',
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
}
