<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganisationLanguage extends Model
{
    protected $table = 'organisation_organisation_languages';
    protected $keyType = 'string';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'organisation_id',
        'language_id',
        'assessment_period',
    ];

}
