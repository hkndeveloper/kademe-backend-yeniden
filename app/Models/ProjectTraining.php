<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTraining extends Model
{
    protected $fillable = ['project_id', 'period_id', 'title', 'description', 'is_active', 'application_open', 'application_start_at', 'application_end_at', 'quota'];

    protected $casts = ['is_active' => 'boolean', 'application_open' => 'boolean', 'application_start_at' => 'datetime', 'application_end_at' => 'datetime'];

    public function isOpen(): bool
    {
        return $this->is_active && $this->application_open
            && (! $this->application_start_at || $this->application_start_at->lte(now()))
            && (! $this->application_end_at || $this->application_end_at->gte(now()));
    }

    public function modules()
    {
        return $this->hasMany(ProjectModule::class, 'training_id');
    }
}
