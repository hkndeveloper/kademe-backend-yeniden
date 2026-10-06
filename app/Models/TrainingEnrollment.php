<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingEnrollment extends Model
{
    protected $fillable = ['training_id', 'user_id', 'application_id', 'status'];
}
