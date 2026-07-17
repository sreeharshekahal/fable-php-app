<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    protected $table = 'organisation_organisation';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'created_by_id',
        'created',
        'updated',
        'help_word_analysis'
    ];

    public function groups()
    {
        return $this->hasMany(OrganisationGroup::class, 'organisation_id');
    }

    public function grades()
    {
        return $this->belongsToMany(Grade::class, 'organisation_organisation_grades', 'organisation_id', 'grade_id');
    }

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'organisation_organisation_languages', 'organisation_id', 'language_id');
    }

    public function teachers()
    {
        return $this->hasMany(Teacher::class, 'organisation_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'organisation_id');
    }

    /**
     * Create default groups for a given grade if they do not exist.
     * Replicates Python's organisation_receiver signal logic.
     *
     * @param Grade $grade
     * @return void
     */
    public function createGroupsForGrade(Grade $grade)
    {
        if ((int)$grade->level === 6) {
            // If a grade's level is 6 (Alumni), creates a single group mapped to rank 1.
            $level = Level::where('rank', 1)->first();
            if ($level) {
                OrganisationGroup::firstOrCreate([
                    'organisation_id' => $this->id,
                    'grade_id' => $grade->id,
                    'level_id' => $level->id,
                ], [
                    'id' => (string) Str::uuid(),
                    'title' => $grade->title,
                    'description' => null,
                    'created' => now(),
                    'updated' => now(),
                ]);
            }
        } else {
            // If a grade's level is not 6, creates a group for each level.
            $levels = Level::all();
            foreach ($levels as $level) {
                OrganisationGroup::firstOrCreate([
                    'organisation_id' => $this->id,
                    'grade_id' => $grade->id,
                    'level_id' => $level->id,
                ], [
                    'id' => (string) Str::uuid(),
                    'title' => $grade->title . ' ' . $level->title,
                    'description' => null,
                    'created' => now(),
                    'updated' => now(),
                ]);
            }
        }
    }
}
