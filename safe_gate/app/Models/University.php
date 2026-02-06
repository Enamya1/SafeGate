<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'latitude',
        'longitude',
        'pic',
    ];
}
