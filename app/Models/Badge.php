<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'icon_path',
        'project_id',
        'tier',
        'required_points',
        'frame_style',
        'title_label',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_badges')->withPivot('awarded_at', 'project_id', 'awarded_by')->withTimestamps();
    }
}
