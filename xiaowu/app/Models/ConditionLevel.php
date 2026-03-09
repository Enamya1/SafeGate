<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConditionLevel extends Model
{
    protected $fillable = [
        'name',
        'description',
        'sort_order',
        'level',
    ];
}
