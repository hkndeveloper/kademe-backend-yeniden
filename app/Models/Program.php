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
        'qr_token',
        'qr_expires_at',
        'qr_rotation_seconds',
        'status',
        'created_by',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'guest_info' => 'array',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'qr_expires_at' => 'datetime',
    ];

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

    public function calendarEvent()
    {
        return $this->hasOne(CalendarEvent::class);
    }
}
