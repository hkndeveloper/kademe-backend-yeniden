<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PersonalityTestQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'personality_test_template_id',
        'question_key',
        'category',
        'text',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(PersonalityTestTemplate::class, 'personality_test_template_id');
    }
}
