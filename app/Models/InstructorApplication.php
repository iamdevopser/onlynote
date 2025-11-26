<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstructorApplication extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'expertise',
        'bio',
        'experience',
        'topics',
        'cv_path',
        'video',
        'kvkk_onay',
    ];
}
