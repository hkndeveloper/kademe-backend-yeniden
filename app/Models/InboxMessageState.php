<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxMessageState extends Model
{
    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'read_at',
        'is_starred',
        'is_pinned',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'is_starred' => 'boolean',
        'is_pinned' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
