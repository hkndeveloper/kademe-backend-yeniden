<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'type',
        'target_unit',
        'target_unit_id',
        'target_user_id',
        'target_membership_id',
        'description',
        'status',
        'response_file_path',
        'project_id',
        'period_id',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function targetUnit()
    {
        return $this->belongsTo(CoordinationUnit::class, 'target_unit_id');
    }

    public function targetMembership()
    {
        return $this->belongsTo(CoordinationUnitMembership::class, 'target_membership_id');
    }

    public function statusHistories()
    {
        return $this->morphMany(WorkflowStatusHistory::class, 'subject');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function period()
    {
        return $this->belongsTo(Period::class);
    }
}
