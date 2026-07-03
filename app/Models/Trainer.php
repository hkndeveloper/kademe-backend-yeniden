<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trainer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'title',
        'organization',
        'expertise',
        'status',
        'last_worked_at',
        'bio',
        'notes',
        'kademe_comment',
        'created_by',
        'updated_by',
        'comment_updated_by',
        'comment_updated_at',
    ];

    protected $casts = [
        'last_worked_at' => 'date',
        'comment_updated_at' => 'datetime',
    ];

    protected $appends = [
        'full_name',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function commentUpdater()
    {
        return $this->belongsTo(User::class, 'comment_updated_by');
    }
}