<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sight extends Model
{
    protected $table = 'passage_sight';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'subcategory',
    ];

    public function frys()
    {
        return $this->hasMany(Fry::class, 'sight_id');
    }
}
