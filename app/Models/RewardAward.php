<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardAward extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'participant_id',
        'reward_tier_id',
        'awarded_by',
        'reward_name',
        'status',
        'awarded_at',
        'note',
    ];

    protected $casts = [
        'awarded_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function tier()
    {
        return $this->belongsTo(RewardTier::class, 'reward_tier_id');
    }

    public function awarder()
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }
}
