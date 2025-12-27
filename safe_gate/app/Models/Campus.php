<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    protected $fillable = [
        'campus_name',
        'domain',
        'location',
        'is_active',
    ];
}
