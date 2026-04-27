<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'surname',
        'email',
        'password',
        'phone',
        'address',
        'tc_no',
        'birth_date',
        'university',
        'department',
        'class_year',
        'hometown',
        'profile_photo_path',
        'role',
        'status',
        'blacklist_count',
        'blacklisted_until',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'kvkk_consent_at',
        'kvkk_forget_requested_at',
        'kvkk_forgotten',
        'yok_verified',
        'tc_verified',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'tc_no',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tc_no' => 'encrypted', // KVKK kapsamında şifreli saklanır
            'birth_date' => 'date',
            'blacklisted_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'kvkk_consent_at' => 'datetime',
            'kvkk_forget_requested_at' => 'datetime',
            'kvkk_forgotten' => 'boolean',
            'yok_verified' => 'boolean',
            'tc_verified' => 'boolean',
        ];
    }

    // --- İLİŞKİLER --- //

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function staffProfile()
    {
        return $this->hasOne(StaffProfile::class);
    }

    // Koordinatör olduğu projeler
    public function coordinatedProjects()
    {
        return $this->belongsToMany(Project::class, 'project_coordinators');
    }

    // Katılımcı olduğu projeler
    public function participations()
    {
        return $this->hasMany(Participant::class);
    }

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function digitalBohcas()
    {
        return $this->hasMany(DigitalBohca::class);
    }

    public function assignmentSubmissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'user_badges')->withPivot('awarded_at', 'project_id')->withTimestamps();
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function submittedFinancials()
    {
        return $this->hasMany(FinancialTransaction::class, 'submitted_by');
    }

    public function supportTickets()
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function permissionOverrides()
    {
        return $this->hasMany(UserPermissionOverride::class);
    }
}
