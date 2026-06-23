<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Mentor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'name',
        'bio',
        'expertise',
        'photo_path',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function participants()
    {
        $pivotColumns = ['period_id'];

        if (Schema::hasColumn('participant_mentor', 'assigned_by')) {
            $pivotColumns[] = 'assigned_by';
        }

        if (Schema::hasColumn('participant_mentor', 'note')) {
            $pivotColumns[] = 'note';
        }

        return $this->belongsToMany(Participant::class, 'participant_mentor')
                    ->withPivot($pivotColumns)
                    ->withTimestamps();
    }
}
