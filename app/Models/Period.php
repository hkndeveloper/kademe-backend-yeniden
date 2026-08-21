<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Period extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'start_date',
        'end_date',
        'credit_start_amount',
        'credit_threshold',
        'status',
        'lifecycle_version',
        'activated_at',
        'activated_by',
        'closing_started_at',
        'closing_started_by',
        'completed_at',
        'completed_by',
        'reopened_at',
        'reopened_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'lifecycle_version' => 'integer',
        'activated_at' => 'datetime',
        'closing_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function participants()
    {
        return $this->hasMany(Participant::class);
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }

    public function applicationWindow()
    {
        return $this->hasOne(ApplicationWindow::class);
    }

    public function archives()
    {
        return $this->hasMany(PeriodArchive::class);
    }

    public function latestArchive()
    {
        return $this->hasOne(PeriodArchive::class)->latestOfMany();
    }

    public function lifecycleEvents()
    {
        return $this->hasMany(PeriodLifecycleEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function activator()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function closingStarter()
    {
        return $this->belongsTo(User::class, 'closing_started_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reopener()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
