<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectModuleEnrollment extends Model
{
    protected $fillable = [
        'project_module_id',
        'user_id',
        'participant_id',
        'status',
        'consented_at',
        'reviewed_at',
        'reviewed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(ProjectModule::class, 'project_module_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
