<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Participant extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'project_id',
        'period_id',
        'status',
        'graduation_status',
        'graduation_note',
        'credit',
        'waitlist_order',
        'enrolled_at',
        'graduated_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'graduated_at' => 'datetime',
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

    public function creditLogs()
    {
        return $this->hasMany(CreditLog::class);
    }

    public function mentors()
    {
        return $this->belongsToMany(Mentor::class, 'participant_mentor')
            ->withPivot(['period_id', 'assigned_by', 'note'])
            ->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}


