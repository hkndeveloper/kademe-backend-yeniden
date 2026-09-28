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
        'consent_text_snapshot',
        'consent_accepted_at',
        'receipt_email_status',
        'decision_email_status',
        'decision_email_key',
    ];

    protected $casts = [
        'consent_accepted_at' => 'datetime',
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
