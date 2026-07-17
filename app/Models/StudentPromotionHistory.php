<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentPromotionHistory extends Model
{
    protected $table = 'organisation_studentpromotionhistory';
    public $timestamps = true;

    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'student_id',
        'grade_id',
    ];

    // Relationships
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }
}
