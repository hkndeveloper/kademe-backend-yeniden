<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SupportTicket extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'subject',
        'message',
        'attachment_path',
        'category',
        'project_id',
        'period_id',
        'assigned_to',
        'assigned_unit_id',
        'status',
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

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedUnit()
    {
        return $this->belongsTo(CoordinationUnit::class, 'assigned_unit_id');
    }

    public function statusHistories()
    {
        return $this->morphMany(WorkflowStatusHistory::class, 'subject');
    }

    public function replies()
    {
        return $this->hasMany(SupportReply::class, 'ticket_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
