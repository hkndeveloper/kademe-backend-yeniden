<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowStatusHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'from_status',
        'to_status',
        'changed_by',
        'unit_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function unit()
    {
        return $this->belongsTo(CoordinationUnit::class, 'unit_id');
    }
}
