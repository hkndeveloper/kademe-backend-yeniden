<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardAward extends Model
{
    use HasFactory;

    /** status: given | delivered | cancelled */
    protected $fillable = [
        'project_id',
        'participant_id',
        'reward_tier_id',
        'awarded_by',
        'reward_name',
        'status',
        'awarded_at',
        'delivered_at',
        'delivered_by',
        'note',
    ];

    protected $casts = [
        'awarded_at'   => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * Hediyeyi teslim edildi olarak isaretler.
     */
    public function markDelivered(int $deliveredById): void
    {
        $this->update([
            'status'       => 'delivered',
            'delivered_at' => now(),
            'delivered_by' => $deliveredById,
        ]);
    }

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
