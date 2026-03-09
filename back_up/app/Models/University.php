<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'website',
        'latitude',
        'longitude',
        'address',
        'pic',
        'contact_email',
        'contact_phone',
        'description',
    ];

    protected $casts = [
        'pic' => 'array',
    ];
}
