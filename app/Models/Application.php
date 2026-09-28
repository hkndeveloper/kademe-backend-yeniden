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
        'application_window_id',
        'program_id',
        'application_form_id',
        'form_data',
        'consent_text_snapshot',
        'consent_accepted_at',
        'status',
        'waitlist_order',
        'waitlist_invited_at',
        'waitlist_invitation_expires_at',
        'waitlist_invitation_delivery_status',
        'waitlist_invitation_response_seconds',
        'rejection_reason',
        'interview_at',
        'interview_passed_at',
        'auto_rejected',
        'auto_rejection_reason',
        'screening_review_reason',
        'auto_rejection_corrected_at',
        'auto_rejection_corrected_by',
        'auto_rejection_corrected_by_name',
        'auto_rejection_correction_reason',
        'evaluation_note',
    ];

    protected $casts = [
        'form_data' => 'array',
        'consent_accepted_at' => 'datetime',
        'interview_at' => 'datetime',
        'interview_passed_at' => 'datetime',
        'waitlist_invited_at' => 'datetime',
        'waitlist_invitation_expires_at' => 'datetime',
        'waitlist_invitation_response_seconds' => 'integer',
        'auto_rejected' => 'boolean',
        'auto_rejection_corrected_at' => 'datetime',
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

    public function applicationWindow()
    {
        return $this->belongsTo(ApplicationWindow::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function form()
    {
        return $this->belongsTo(ApplicationForm::class, 'application_form_id');
    }

    public function usesInterview(): bool
    {
        // List/detail queries may already carry partial relations without this
        // field; the persisted application setting must decide the workflow.
        $windowSetting = $this->application_window_id !== null
            ? $this->applicationWindow()->value('has_interview')
            : null;

        return (bool) ($windowSetting ?? $this->project()->value('has_interview'));
    }

    public function projectQuota(): ?int
    {
        $this->loadMissing(['applicationWindow:id,quota', 'project:id,quota']);
        $quota = $this->applicationWindow?->quota ?? $this->project?->quota;

        return $quota === null ? null : (int) $quota;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
