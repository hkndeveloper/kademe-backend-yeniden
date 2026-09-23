<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Project extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'special_modules',
        'description',
        'short_description',
        'cover_image_path',
        'gallery_paths',
        'status',
        'application_open',
        'application_start_at',
        'application_end_at',
        'next_application_date',
        'has_interview',
        'quota',
        'created_by',
    ];

    protected $casts = [
        'gallery_paths' => 'array',
        'special_modules' => 'array',
        'application_start_at' => 'datetime',
        'application_end_at' => 'datetime',
        'next_application_date' => 'date',
        'application_open' => 'boolean',
        'has_interview' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function coordinators()
    {
        return $this->belongsToMany(User::class, 'project_coordinators');
    }

    public function assignedStaff()
    {
        return $this->belongsToMany(User::class, 'project_staff_assignments');
    }

    public function coordinationUnit()
    {
        return $this->hasOne(CoordinationUnit::class);
    }

    public function coordinationUnitResponsibilities()
    {
        return $this->hasMany(CoordinationUnitProjectResponsibility::class);
    }

    public function periods()
    {
        return $this->hasMany(Period::class);
    }

    public function activePeriods()
    {
        return $this->hasMany(Period::class)->where('status', 'active');
    }

    public function currentPeriod()
    {
        return $this->belongsTo(Period::class, 'current_period_id');
    }

    public function currentPeriodOrLegacy(): ?Period
    {
        if ($this->current_period_id !== null) {
            if ($this->relationLoaded('currentPeriod')) {
                return $this->currentPeriod;
            }

            return $this->currentPeriod()->first();
        }

        if (config('period_lifecycle.enforce_current_period_pointer', false)) {
            return null;
        }

        if (config('period_lifecycle.log_legacy_pointer_fallback', true)) {
            Log::warning('period_lifecycle.legacy_current_period_fallback_used', [
                'project_id' => (int) $this->id,
            ]);
        }

        if ($this->relationLoaded('periods')) {
            return $this->periods
                ->first(fn (Period $period) => in_array($period->status, ['active', 'closing'], true));
        }

        return $this->periods()
            ->whereIn('status', ['active', 'closing'])
            ->orderByDesc('start_date')
            ->first();
    }

    public function participants()
    {
        return $this->hasMany(Participant::class);
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }

    public function applicationWindows()
    {
        return $this->hasMany(ApplicationWindow::class);
    }

    public function kademeModules()
    {
        return $this->hasMany(ProjectModule::class)->orderBy('sort_order');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
