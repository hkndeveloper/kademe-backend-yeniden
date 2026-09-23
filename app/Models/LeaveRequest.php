<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'unit_id',
        'membership_id',
        'position_snapshot',
        'reviewer_scope',
        'start_date',
        'end_date',
        'reason',
        'status',
        'approved_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function unit()
    {
        return $this->belongsTo(CoordinationUnit::class, 'unit_id');
    }

    public function membership()
    {
        return $this->belongsTo(CoordinationUnitMembership::class, 'membership_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusHistories()
    {
        return $this->morphMany(WorkflowStatusHistory::class, 'subject');
    }
}
