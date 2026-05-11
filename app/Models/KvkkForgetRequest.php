<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KvkkForgetRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'request_note',
        'reviewer_note',
        'reviewed_by',
        'reviewed_at',
        'anonymized_at',
        'anonymization_summary',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'anonymized_at' => 'datetime',
        'anonymization_summary' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
