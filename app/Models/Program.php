<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'period_id',
        'title',
        'description',
        'location',
        'latitude',
        'longitude',
        'radius_meters',
        'guest_info',
        'start_at',
        'end_at',
        'credit_deduction',
        'application_quota',
        'target_audience',
        'feedback_form_template_id',
        'qr_token',
        'qr_expires_at',
        'qr_rotation_seconds',
        'status',
        'is_public',
        'is_featured',
        'created_by',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'guest_info' => 'array',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'qr_expires_at' => 'datetime',
        'target_audience' => 'array',
        'is_public' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function targetAudience(): array
    {
        $audience = collect($this->target_audience ?: ['student'])
            ->filter(fn ($item) => in_array($item, ['student', 'alumni'], true))
            ->unique()
            ->values()
            ->all();

        return $audience ?: ['student'];
    }

    public function isTargetedTo(string $role): bool
    {
        return in_array($role, $this->targetAudience(), true);
    }

    public function isAttendanceWindowOpen($at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();

        if ($this->start_at && $at->lt($this->start_at)) {
            return false;
        }

        if ($this->end_at && $at->gt($this->end_at)) {
            return false;
        }

        return true;
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function feedbacks()
    {
        return $this->hasMany(Feedback::class);
    }

    public function feedbackFormTemplate()
    {
        return $this->belongsTo(FeedbackFormTemplate::class, 'feedback_form_template_id');
    }

    public function calendarEvent()
    {
        return $this->hasOne(CalendarEvent::class);
    }

    public function photos()
    {
        return $this->hasMany(ProgramPhoto::class)->orderBy('sort_order')->orderBy('id');
    }
}
