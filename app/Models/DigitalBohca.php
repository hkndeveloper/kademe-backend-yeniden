<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigitalBohca extends Model
{
    use HasFactory;

    protected $table = 'digital_bohca';

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'description',
        'file_path',
        'file_type',
        'visible_to_student',
        'uploaded_by',
    ];

    protected $casts = [
        'visible_to_student' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
