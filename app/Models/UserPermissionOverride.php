<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPermissionOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'permission_name',
        'effect',
        'scope_type',
        'scope_payload',
    ];

    protected function casts(): array
    {
        return [
            'scope_payload' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
