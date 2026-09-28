<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramAbsence extends Model
{
    protected $fillable = [
        'participant_id', 'user_id', 'project_id', 'period_id', 'program_id',
        'excused', 'excuse_reason', 'reviewed_by', 'reviewed_at', 'recorded_by',
    ];

    protected $casts = [
        'excused' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }
}
