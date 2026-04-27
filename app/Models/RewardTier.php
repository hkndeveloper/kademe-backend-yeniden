<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'min_badges',
        'min_credits',
        'reward_description',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
