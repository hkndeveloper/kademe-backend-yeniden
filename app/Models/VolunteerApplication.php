<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VolunteerApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'volunteer_opportunity_id',
        'user_id',
        'motivation_text',
        'notes',
        'status',
        'evaluation_note',
    ];

    public function opportunity()
    {
        return $this->belongsTo(VolunteerOpportunity::class, 'volunteer_opportunity_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
