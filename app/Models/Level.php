<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    protected $table = 'common_level';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'rank'
    ];

    // Relationships
    public function groups()
    {
        return $this->hasMany(OrganisationGroup::class, 'level_id');
    }

    public function groupingParameters()
    {
        return $this->hasMany(GroupingParameter::class, 'level_id');
    }
}
