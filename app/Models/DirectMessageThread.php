<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectMessageThread extends Model
{
    protected $fillable = ['project_id', 'created_by', 'recipient_id', 'subject', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function project() { return $this->belongsTo(Project::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function recipient() { return $this->belongsTo(User::class, 'recipient_id'); }
    public function messages() { return $this->hasMany(DirectMessage::class, 'thread_id'); }
}
