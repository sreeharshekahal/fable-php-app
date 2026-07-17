<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $table = 'access_student';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'date_of_birth',
        'gender',
        'is_english_second_language',
        'disability',
        'division',
        'user_id',
        'grade_id',
        'organisation_id',
        'picture',
        'group_id',
        'group_hindi_id',
        'group_marathi_id'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function group()
    {
        return $this->belongsTo(OrganisationGroup::class, 'group_id');
    }

    public function group_hindi()
    {
        return $this->belongsTo(OrganisationGroup::class, 'group_hindi_id');
    }

    public function group_marathi()
    {
        return $this->belongsTo(OrganisationGroup::class, 'group_marathi_id');
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'student_id');
    }

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'access_student_languages', 'student_id', 'language_id')
            ->using(StudentLanguage::class);
    }

    public function promotionHistory()
    {
        return $this->hasMany(StudentPromotionHistory::class, 'student_id');
    }

    public function getIsPromotedAttribute()
    {
        return $this->promotionHistory()->where('grade_id', $this->grade_id)->exists();
    }
}
