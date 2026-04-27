<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpdAppointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'counselor_id',
        'counselee_id',
        'room_id',
        'start_at',
        'end_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'notes' => 'encrypted', // KVKK gereği şifreli
    ];

    public function counselor()
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function counselee()
    {
        return $this->belongsTo(User::class, 'counselee_id');
    }

    public function room()
    {
        return $this->belongsTo(KpdRoom::class, 'room_id');
    }
}
