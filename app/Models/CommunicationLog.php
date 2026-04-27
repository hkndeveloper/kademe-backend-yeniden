<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunicationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'sender_id',
        'recipients_count',
        'recipient_filter',
        'subject',
        'content',
        'attachment_path',
        'status',
        'project_id',
    ];

    protected $casts = [
        'recipient_filter' => 'array',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
