<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AssessmentErrorWord extends Pivot
{
    protected $table = 'assessment_errorwords';
    public $timestamps = false;

    protected $fillable = [
        'assessment_id',
        'word_id',
        'index'
    ];
}
