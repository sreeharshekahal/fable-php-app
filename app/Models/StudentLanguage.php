<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class StudentLanguage extends Pivot
{
    protected $table = 'access_student_languages';
    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'language_id'
    ];
}
