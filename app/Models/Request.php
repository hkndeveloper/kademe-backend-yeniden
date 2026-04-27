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
        'target_user_id',
        'description',
        'status',
        'response_file_path',
        'project_id',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
