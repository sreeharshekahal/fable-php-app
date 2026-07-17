<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AssessmentChecklist extends Pivot
{
    protected $table = 'assessment_assessment_checklists';
    public $timestamps = false;

    protected $fillable = [
        'assessment_id',
        'checklist_id'
    ];
}
