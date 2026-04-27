<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Internship extends Model
{
    use HasFactory;

    protected $fillable = [
        'participant_id',
        'company_name',
        'position',
        'start_date',
        'end_date',
        'description',
        'document_path',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }
}
