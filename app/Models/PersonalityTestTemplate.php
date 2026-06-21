<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonalityTestTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function questions()
    {
        return $this->hasMany(PersonalityTestQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function resultRanges()
    {
        return $this->hasMany(PersonalityTestResultRange::class);
    }
}
