<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $date_of_birth
 * @property int|null $gender
 * @property bool|int $is_english_second_language
 * @property bool|int $disability
 * @property string|null $division
 * @property string $user_id
 * @property string|null $grade_id
 * @property string|null $organisation_id
 * @property string|null $picture
 * @property string|null $group_id
 * @property string|null $group_hindi_id
 * @property string|null $group_marathi_id
 *
 * @property User|null $user
 * @property Grade|null $grade
 * @property OrganisationGroup|null $group
 * @property OrganisationGroup|null $group_hindi
 * @property OrganisationGroup|null $group_marathi
 * @property Organisation|null $organisation
 * @property \Illuminate\Database\Eloquent\Collection|Language[] $languages
 */
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
