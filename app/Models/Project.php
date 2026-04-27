<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'type',
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

    public function periods()
    {
        return $this->hasMany(Period::class);
    }

    public function activePeriods()
    {
        return $this->hasMany(Period::class)->where('status', 'active');
    }

    public function participants()
    {
        return $this->hasMany(Participant::class);
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }
}
