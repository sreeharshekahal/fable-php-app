<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'auth_user';
    public $timestamps = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'username',
        'password',
        'is_superuser',
        'is_staff',
        'is_active',
        'date_joined'
    ];

    protected $hidden = [
        'password',
    ];
}
