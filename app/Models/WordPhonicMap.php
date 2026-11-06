<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordPhonicMap extends Model
{
    protected $table = 'passage_wordphonicmap';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'id',
        'word_id',
        'phonic_id',
        'created',
        'updated',
    ];

    public function word()
    {
        return $this->belongsTo(PassageWord::class, 'word_id');
    }

    public function phonic()
    {
        return $this->belongsTo(Phonic::class, 'phonic_id');
    }
}

