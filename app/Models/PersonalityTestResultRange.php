<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonalityTestResultRange extends Model
{
    use HasFactory;

    protected $fillable = [
        'personality_test_template_id',
        'category',
        'summary',
    ];

    public function template()
    {
        return $this->belongsTo(PersonalityTestTemplate::class, 'personality_test_template_id');
    }
}
