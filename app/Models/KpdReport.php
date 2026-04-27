<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpdReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'counselor_id',
        'title',
        'file_path',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function counselor()
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }
}
