<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EurodeskProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'title',
        'partner_organizations',
        'grant_amount',
        'grant_status',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'partner_organizations' => 'array',
        'grant_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function partnerships()
    {
        return $this->hasMany(EurodeskPartnership::class, 'eurodesk_project_id');
    }
}
