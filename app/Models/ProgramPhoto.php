<?php

namespace App\Models;

use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'program_id',
        'url',
        'caption',
        'sort_order',
        'created_by',
    ];

    /**
     * `url` sütununda disk göreli yol (örn. program-photos/1/uuid.jpg) tutulur; API’de her zaman erişilebilir tam URL döner.
     */
    public function getUrlAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return MediaStorage::url($value);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
