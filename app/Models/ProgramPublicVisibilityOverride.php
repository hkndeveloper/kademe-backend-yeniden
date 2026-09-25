<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgramPublicVisibilityOverride extends Model
{
    protected $primaryKey = 'program_id';

    public $incrementing = false;

    protected $fillable = ['program_id', 'is_public', 'updated_by'];

    protected $casts = ['is_public' => 'boolean'];
}
