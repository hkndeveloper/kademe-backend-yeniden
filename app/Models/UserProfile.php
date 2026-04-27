<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'personality_test_data',
        'digital_cv_data',
        'motivation_message',
        'linkedin_url',
        'github_url',
        'instagram_url',
    ];

    protected $casts = [
        'personality_test_data' => 'array',
        'digital_cv_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
