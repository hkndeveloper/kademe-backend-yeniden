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
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
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
}
