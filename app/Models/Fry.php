<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fry extends Model
{
    protected $table = 'passage_fry';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'sight_id',
        'word',
    ];

    public function sight()
    {
        return $this->belongsTo(Sight::class, 'sight_id');
    }
}
