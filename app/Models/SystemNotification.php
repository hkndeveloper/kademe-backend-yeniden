<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'action_url',
        'is_read',
        'read_at',
        'notifiable_type',
        'notifiable_id',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable()
    {
        return $this->morphTo();
    }

    /**
     * Kullanici icin yeni bildirim olusturur (static helper).
     */
    public static function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?string $notifiableType = null,
        ?int $notifiableId = null
    ): static {
        return static::create([
            'user_id'         => $userId,
            'type'            => $type,
            'title'           => $title,
            'body'            => $body,
            'action_url'      => $actionUrl,
            'notifiable_type' => $notifiableType,
            'notifiable_id'   => $notifiableId,
        ]);
    }
}
