<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accuracy extends Model
{
    protected $table = 'common_accuracy';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'grade_id',
        'level_id',
        'language_id',
        'percentage',
        'benchmark_template_id',
        'assessment_period',
    ];

    protected $casts = [
        'percentage' => 'integer',
    ];

    // Relationships
    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function benchmarkTemplate()
    {
        return $this->belongsTo(BenchmarkTemplate::class, 'benchmark_template_id');
    }
}
