<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassageWord extends Model
{
    protected $table = 'passage_word';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'text',
    ];
}

