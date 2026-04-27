<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EurodeskPartnership extends Model
{
    use HasFactory;

    protected $fillable = [
        'eurodesk_project_id',
        'organization_name',
        'country',
        'contact_info',
    ];

    public function eurodeskProject()
    {
        return $this->belongsTo(EurodeskProject::class, 'eurodesk_project_id');
    }
}
