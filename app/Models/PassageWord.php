<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassageWord extends Model
{
    protected $table = 'passage_word';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'id',
        'text',
        'created',
        'updated',
    ];
}


