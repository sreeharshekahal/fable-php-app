<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Morpheme extends Model
{
    protected $table = 'passage_morpheme';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'subcategory',
        'subcategory_substrings',
        'subcategory_code',
    ];

    protected $casts = [
        'subcategory_substrings' => 'array',
    ];
}
