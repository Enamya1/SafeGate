<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetupLocation extends Model
{
    protected $fillable = [
        'campus_id',
        'name',
        'description',
        'is_active',
    ];
}
