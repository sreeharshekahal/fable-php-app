<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganisationGroup extends Model
{
    protected $table = 'organisation_group';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'organisation_id',
        'grade_id',
        'level_id',
        'created',
        'updated',
        'goal'
    ];

    // Relationships
    public function students()
    {
        return $this->hasMany(Student::class, 'group_id');
    }

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function teachers()
    {
        return $this->belongsToMany(Teacher::class, 'access_teacher_groups', 'group_id', 'teacher_id')
            ->using(TeacherGroup::class);
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'group_id');
    }
}
