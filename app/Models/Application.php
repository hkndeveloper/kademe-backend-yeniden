<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'period_id',
        'application_form_id',
        'form_data',
        'status',
        'rejection_reason',
        'interview_at',
        'auto_rejected',
        'auto_rejection_reason',
        'evaluation_note',
    ];

    protected $casts = [
        'form_data' => 'array',
        'interview_at' => 'datetime',
        'auto_rejected' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function form()
    {
        return $this->belongsTo(ApplicationForm::class, 'application_form_id');
    }
}
