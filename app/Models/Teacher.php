<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $table = 'access_teacher';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'user_id',
        'organisation_id'
        // Add other fields from access_teacher table if needed
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function groups()
    {
        return $this->belongsToMany(OrganisationGroup::class, 'access_teacher_groups', 'teacher_id', 'group_id');
    }

    public function languages()
    {
        return $this->belongsToMany(Language::class, 'access_teacher_languages', 'teacher_id', 'language_id');
    }

    public function getFirstNameAttribute()
    {
        return $this->user ? $this->user->first_name : null;
    }

    public function getLastNameAttribute()
    {
        return $this->user ? $this->user->last_name : null;
    }

    public function getEmailAttribute()
    {
        return $this->user ? $this->user->email : null;
    }

    public function getUsernameAttribute()
    {
        return $this->user ? $this->user->username : null;
    }
}
