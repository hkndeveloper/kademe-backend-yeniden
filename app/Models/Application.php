<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Application extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'project_id',
        'period_id',
        'program_id',
        'application_form_id',
        'form_data',
        'status',
        'waitlist_order',
        'waitlist_invited_at',
        'waitlist_invitation_expires_at',
        'rejection_reason',
        'interview_at',
        'auto_rejected',
        'auto_rejection_reason',
        'evaluation_note',
    ];

    protected $casts = [
        'form_data' => 'array',
        'interview_at' => 'datetime',
        'waitlist_invited_at' => 'datetime',
        'waitlist_invitation_expires_at' => 'datetime',
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

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function form()
    {
        return $this->belongsTo(ApplicationForm::class, 'application_form_id');
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}

