<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupingParameter extends Model
{
    protected $table = 'common_groupingparameter';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
        'upper_bound',
        'lower_bound',
        'goal',
        'level_id',
        'grade_id',
        'language_id',
        'benchmark_template_id',
        'assessment_period',
    ];

    // Relationships
    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function benchmarkTemplate()
    {
        return $this->belongsTo(BenchmarkTemplate::class, 'benchmark_template_id');
    }
}
