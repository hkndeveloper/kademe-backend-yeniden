<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ApplicationWindow extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'project_id',
        'period_id',
        'is_open',
        'starts_at',
        'ends_at',
        'next_application_date',
        'has_interview',
        'quota',
        'change_note',
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
        'updated_by',
        'status_changed_at',
    ];

    protected $casts = [
        'is_open' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'next_application_date' => 'date',
        'has_interview' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'status_changed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('application_intake')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
