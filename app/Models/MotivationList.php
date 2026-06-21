<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotivationList extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'rotation_period',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function quotes()
    {
        return $this->hasMany(MotivationQuote::class);
    }
}
