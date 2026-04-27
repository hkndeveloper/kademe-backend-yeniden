<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpdRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function appointments()
    {
        return $this->hasMany(KpdAppointment::class, 'room_id');
    }
}
