<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'program_id',
        'title',
        'description',
        'location',
        'start_at',
        'end_at',
        'google_event_id',
        'assigned_users',
        'created_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'assigned_users' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
