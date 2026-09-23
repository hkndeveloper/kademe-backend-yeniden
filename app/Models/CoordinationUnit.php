<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CoordinationUnit extends Model
{
    use HasFactory, SoftDeletes;

    public const KIND_PROJECT = 'project';

    public const KIND_SERVICE = 'service';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PASSIVE = 'passive';

    protected $fillable = [
        'code',
        'name',
        'kind',
        'project_id',
        'status',
        'description',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function memberships()
    {
        return $this->hasMany(CoordinationUnitMembership::class, 'unit_id');
    }

    public function projectResponsibilities()
    {
        return $this->hasMany(CoordinationUnitProjectResponsibility::class, 'unit_id');
    }

    public function permissionRules()
    {
        return $this->hasMany(CoordinationUnitPermissionRule::class, 'unit_id');
    }

    public function targetedRequests()
    {
        return $this->hasMany(Request::class, 'target_unit_id');
    }

    public function assignedSupportTickets()
    {
        return $this->hasMany(SupportTicket::class, 'assigned_unit_id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'unit_id');
    }

    public function processedFinancialTransactions()
    {
        return $this->hasMany(FinancialTransaction::class, 'processing_unit_id');
    }
}
