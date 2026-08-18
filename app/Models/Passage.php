<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'number',
        'status'
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

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
