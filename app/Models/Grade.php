<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $table = 'common_grade';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'id',
        'level',
        'title',
        'description',
        'created',
        'updated'
    ];

    // Relationships
    public function groups()
    {
        return $this->hasMany(OrganisationGroup::class, 'grade_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'grade_id');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'grade_id');
    }

    public function accuracy($benchmarkTemplateId = null)
    {
        return $this->hasOne(Accuracy::class, 'grade_id')
            ->where('benchmark_template_id', $benchmarkTemplateId);
    }

    public function accuracies()
    {
        return $this->hasMany(Accuracy::class, 'grade_id');
    }

    public function groupingParameters()
    {
        return $this->hasMany(GroupingParameter::class, 'grade_id');
    }

    public function passages()
    {
        return $this->hasMany(Passage::class, 'grade_id');
    }
}
