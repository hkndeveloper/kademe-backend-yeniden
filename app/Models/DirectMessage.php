<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectMessage extends Model
{
    protected $fillable = ['thread_id', 'sender_id', 'body', 'attachment_path', 'attachment_name', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function thread() { return $this->belongsTo(DirectMessageThread::class, 'thread_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
}
