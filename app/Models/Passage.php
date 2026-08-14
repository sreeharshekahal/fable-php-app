<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $passage_name
 * @property string $raw_content
 * @property string|null $content
 * @property string $grade_id
 * @property string $language_id
 * @property int|string $number
 * @property float|null $readability_score
 * @property string|null $created_by_id
 * @property \Carbon\Carbon|string|null $created
 * @property \Carbon\Carbon|string|null $updated
 *
 * @property Grade|null $grade
 * @property Language|null $language
 * @property \Illuminate\Database\Eloquent\Collection|Assessment[] $assessments
 */
class Passage extends Model
{
    protected $table = 'passage_passage';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'passage_name',
        'raw_content',
        'grade_id',
        'language_id',
        'number'
    ];

    // Relationships
    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function assessments()
    {
        return $this->hasMany(Assessment::class, 'passage_id');
    }
}

