<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    protected $table = 'assessment_assessment';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'student_id',
        'passage_id',
        'type',
        'last_word_index',
        'no_error_words',
        'created',
        'audio',
        'retell_audio',
        'conducted_by_id',
        'organisation_id',
        'group_id',
        'grade_id',
        'notes',
        'updated',
        'last_word',
        'assessment_period',
        'benchmark_goal',
    ];

    protected $casts = [
        'created' => 'datetime',
        'updated' => 'datetime',
    ];

    // Relationships
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function passage()
    {
        return $this->belongsTo(Passage::class, 'passage_id');
    }

    // Accessors
    public function getCorrectWordsAttribute()
    {
        return $this->last_word_index + 1 - $this->no_error_words;
    }

    public function getTotalWordsAttribute()
    {
        return $this->last_word_index + 1;
    }

    public function getScoreAttribute()
    {
        return $this->correct_words;
    }

    public function getAccuracyAttribute()
    {
        if ($this->total_words == 0) {
            return 0;
        }
        return ($this->correct_words / $this->total_words) * 100;
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function group()
    {
        return $this->belongsTo(OrganisationGroup::class, 'group_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function conductedBy()
    {
        return $this->belongsTo(User::class, 'conducted_by_id');
    }

    public function checklists()
    {
        return $this->belongsToMany(Checklist::class, 'assessment_assessment_checklists', 'assessment_id', 'checklist_id')
            ->using(AssessmentChecklist::class);
    }

    public function errorWords()
    {
        return $this->belongsToMany(PassageWord::class, 'assessment_errorwords', 'assessment_id', 'word_id')
            ->using(AssessmentErrorWord::class)
            ->withPivot('index');
    }
}
