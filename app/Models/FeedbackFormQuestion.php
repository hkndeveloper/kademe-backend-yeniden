<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedbackFormQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'feedback_form_template_id',
        'question_key',
        'label',
        'type',
        'options',
        'min_value',
        'max_value',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'min_value' => 'integer',
        'max_value' => 'integer',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(FeedbackFormTemplate::class, 'feedback_form_template_id');
    }
}
