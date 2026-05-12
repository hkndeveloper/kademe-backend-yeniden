<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DigitalBohca extends Model
{
    use HasFactory;

    protected $table = 'digital_bohca';

    /**
     * Gecerli kategori degerleri:
     * general | internship_documents | assignment | certificate | kpd_report | other
     */
    public const CATEGORIES = [
        'general'              => 'Genel',
        'internship_documents' => 'Staj Belgeleri',
        'assignment'           => 'Odev',
        'certificate'          => 'Sertifika',
        'kpd_report'           => 'KPD Raporu',
        'other'                => 'Diger',
    ];

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'description',
        'file_path',
        'file_type',
        'category',
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
