<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectModule extends Model
{
    protected $fillable = [
        'project_id',
        'period_id',
        'title',
        'description',
        'sort_order',
        'is_active',
        'application_open',
        'requires_consent',
        'consent_checkbox_label',
        'warning_text',
        'requires_coordinator_approval',
        'outcomes',
        'instructors',
        'faq_items',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'application_open' => 'boolean',
            'requires_consent' => 'boolean',
            'requires_coordinator_approval' => 'boolean',
            'outcomes' => 'array',
            'instructors' => 'array',
            'faq_items' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ProjectModuleEnrollment::class, 'project_module_id');
    }
}
