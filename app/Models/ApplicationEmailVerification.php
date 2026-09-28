<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationEmailVerification extends Model
{
    protected $fillable = ['project_id', 'email', 'code_hash', 'expires_at', 'attempts', 'consumed_at'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
