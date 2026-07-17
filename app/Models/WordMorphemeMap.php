<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordMorphemeMap extends Model
{
    protected $table = 'passage_wordmorphememap';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'word_id',
        'morpheme_id',
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
