<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RolePermissionScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'role_name',
        'permission_name',
        'scope_type',
        'scope_payload',
    ];

    protected $casts = [
        'scope_payload' => 'array',
    ];
}
