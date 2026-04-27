<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Participant extends Model
{
    use HasFactory;

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
}
