<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Feedback extends Model
{
    use HasFactory;
    private static ?bool $hasPublicIdColumn = null;

    protected $table = 'feedbacks';

    protected $fillable = [
        'program_id',
        'anonymous_token',
        'public_id',
        'responses',
        'submitted_at',
    ];

    protected $casts = [
        'responses' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Feedback $feedback) {
            if (self::usesPublicIdColumn() && ! $feedback->public_id) {
                $feedback->public_id = (string) Str::uuid();
            }
        });
    }

    public static function usesPublicIdColumn(): bool
    {
        if (self::$hasPublicIdColumn === null) {
            self::$hasPublicIdColumn = Schema::hasColumn('feedbacks', 'public_id');
        }

        return self::$hasPublicIdColumn;
    }
}
