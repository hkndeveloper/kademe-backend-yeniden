<?php

namespace App\Models;

use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MotivationQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'motivation_list_id',
        'quote',
        'speaker',
        'image_path',
        'sort_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'image_url',
    ];

    public function list()
    {
        return $this->belongsTo(MotivationList::class, 'motivation_list_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return MediaStorage::url($this->image_path);
    }
}
