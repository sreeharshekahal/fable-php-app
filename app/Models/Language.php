<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $table = 'common_language';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name'
    ];

    // Relationships
    public function passages()
    {
        return $this->hasMany(Passage::class, 'language_id');
    }

    public function groupingParameters()
    {
        return $this->hasMany(GroupingParameter::class, 'language_id');
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'access_student_languages', 'language_id', 'student_id');
    }
}
