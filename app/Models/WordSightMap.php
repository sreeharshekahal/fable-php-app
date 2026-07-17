<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordSightMap extends Model
{
    protected $table = 'passage_wordsightmap';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'word_id',
        'sight_word_id',
    ];

    public function word()
    {
        return $this->belongsTo(PassageWord::class, 'word_id');
    }

    public function sightWord()
    {
        return $this->belongsTo(Sight::class, 'sight_word_id');
    }
}
