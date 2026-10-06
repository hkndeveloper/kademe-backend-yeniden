<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationCandidate extends Model
{
    protected $fillable = ['name', 'surname', 'email', 'phone', 'email_verified_at', 'user_id'];

    protected $casts = ['email_verified_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
