<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'unit',
        'start_date',
        'contract_type',
        'personal_documents',
    ];

    protected $casts = [
        'start_date' => 'date',
        'personal_documents' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
