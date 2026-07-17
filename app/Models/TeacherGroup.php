<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TeacherGroup extends Pivot
{
    protected $table = 'access_teacher_groups';
    public $timestamps = false;

    protected $fillable = [
        'teacher_id',
        'group_id'
    ];
}
