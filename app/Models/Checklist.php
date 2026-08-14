<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $title
 * @property int $type
 * @property string $language_id
 * @property \Carbon\Carbon|string|null $created
 * @property \Carbon\Carbon|string|null $updated
 *
 * @property Language|null $language
 * @property \Illuminate\Database\Eloquent\Collection|Assessment[] $assessments
 */
class Checklist extends Model
{
    protected $table = 'assessment_checklist';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'type',
        'language_id',
        'created',
        'updated'
    ];

    protected $casts = [
        'created' => 'datetime',
        'updated' => 'datetime',
    ];

    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function assessments()
    {
        return $this->belongsToMany(Assessment::class, 'assessment_assessment_checklists', 'checklist_id', 'assessment_id')
            ->using(AssessmentChecklist::class);
    }
}
