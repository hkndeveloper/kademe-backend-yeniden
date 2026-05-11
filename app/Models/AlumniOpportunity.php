<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlumniOpportunity extends Model
{
    protected $fillable = [
        'project_id',
        'created_by',
        'title',
        'kind',
        'summary',
        'body',
        'link_url',
        'starts_at',
        'ends_at',
        'published_at',
        'expires_at',
        'target_audience',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'target_audience' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
