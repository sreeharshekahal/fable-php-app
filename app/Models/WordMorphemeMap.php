<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordMorphemeMap extends Model
{
    protected $table = 'passage_wordmorphememap';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

    protected $fillable = [
        'id',
        'word_id',
        'morpheme_id',
        'created',
        'updated',
    ];

    public function word()
    {
        return $this->belongsTo(PassageWord::class, 'word_id');
    }

    public function morpheme()
    {
        return $this->belongsTo(Morpheme::class, 'morpheme_id');
    }
}

