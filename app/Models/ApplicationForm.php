<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'period_id',
        'program_id',
        'fields',
        'require_consent',
        'consent_text',
        'auto_reject_rules',
        'is_active',
    ];

    protected $casts = [
        'fields' => 'array',
        'auto_reject_rules' => 'array',
        'require_consent' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }
}
